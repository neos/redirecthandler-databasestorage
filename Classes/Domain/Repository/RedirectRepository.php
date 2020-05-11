<?php
declare(strict_types=1);

namespace Neos\RedirectHandler\DatabaseStorage\Domain\Repository;

/*
 * This file is part of the Neos.RedirectHandler.DatabaseStorage package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect;
use Neos\RedirectHandler\RedirectInterface;
use Neos\RedirectHandler\Redirect as RedirectDto;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\Repository;

/**
 * Repository for redirect instances.
 * Note: You should not interact with this repository directly. Instead use the RedirectService!
 *
 * @Flow\Scope("singleton")
 */
class RedirectRepository extends Repository
{
    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var array
     */
    protected $defaultOrderings = [
        'sourceUriPath' => QueryInterface::ORDER_ASCENDING,
        'host' => QueryInterface::ORDER_DESCENDING
    ];

    /**
     * @param string $sourceUriPath
     * @param string $host Full qualified host name
     * @param boolean $fallback If not redirect found, match a redirect with host value as null
     * @return Redirect|null
     */
    public function findOneBySourceUriPathAndHost(string $sourceUriPath, ?string $host = null, bool $fallback = true): ?Redirect
    {
        $query = $this->createQuery();

        if ($fallback === true) {
            $constraints = $query->logicalAnd(
                $query->equals('sourceUriPathHash', md5(trim($sourceUriPath, '/'))),
                $query->logicalOr(
                    $query->equals('host', $host),
                    $query->equals('host', null)
                )
            );
        } else {
            $constraints = $query->logicalAnd(
                $query->equals('sourceUriPathHash', md5(trim($sourceUriPath, '/'))),
                $query->equals('host', $host)
            );
        }

        $query->matching($constraints);

        return $query->execute()->getFirst();
    }

    /**
     * @param string $targetUriPath
     * @param string $host Full qualified host name
     * @return Redirect|null
     */
    public function findOneByTargetUriPathAndHost(string $targetUriPath, ?string $host = null): ?Redirect
    {
        $query = $this->createQuery();

        $query->matching(
            $query->logicalAnd(
                $query->equals('targetUriPathHash', md5(trim($targetUriPath, '/'))),
                $query->logicalOr(
                    $query->equals('host', $host),
                    $query->equals('host', null)
                )
            )
        );

        return $query->execute()->getFirst();
    }

    /**
     * @param string $targetUriPath
     * @param string $host Full qualified host name
     * @return \Iterator<Redirect>
     */
    public function findByTargetUriPathAndHost(string $targetUriPath, ?string $host = null): \Iterator
    {
        if (!empty($host)) {
            $query = $this->entityManager->createQuery(
                'SELECT r FROM Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect r
                WHERE (r.targetUriPathHash = :targetUriPathHash AND r.host = :host)
                OR (r.targetUriPath LIKE :hostAndTargetUri)
            ');
            $query->setParameter('targetUriPathHash', md5(trim($targetUriPath, '/')));
            $query->setParameter('hostAndTargetUri', '%/' . $host . '/' . $targetUriPath);
            $query->setParameter('host', $host);
        } else {
            $query = $this->entityManager->createQuery('SELECT r FROM Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect r WHERE r.targetUriPathHash = :targetUriPathHash AND r.host IS NULL');
            $query->setParameter('targetUriPathHash', md5(trim($targetUriPath, '/')));
        }

        return $this->iterate($query->iterate());
    }

    /**
     * @param bool $onlyActive Filters redirects which start and end datetime match the current datetime
     * @param string|null $type Filters redirects by their type
     * @return QueryBuilder
     * @throws \Exception
     */
    protected function buildQuery(bool $onlyActive = false, ?string $type = null): QueryBuilder
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $query = $queryBuilder
            ->select('r')
            ->from($this->getEntityClassName(), 'r');

        if ($onlyActive) {
            $query->andWhere('(r.startDateTime < :now OR r.startDateTime IS NULL) AND (r.endDateTime > :now OR r.endDateTime IS NULL)')
                ->setParameter('now', new \DateTime());
        }

        if (!empty($type)) {
            $query->andWhere('r.type = :type')
                ->setParameter('type', $type);
        }

        $query->orderBy('r.host', 'ASC');
        $query->addOrderBy('r.sourceUriPath', 'ASC');

        return $query;
    }

    /**
     * Finds all redirects filtered by the parameters and returns an IterableResult
     *
     * @param string $host Fully qualified host name
     * @param bool $onlyActive Filters redirects which start and end datetime match the current datetime
     * @param string|null $type Filters redirects by their type
     * @param callable $callback
     * @return \Generator<Redirect>
     * @throws \Exception
     */
    public function findAllWithParameters(?string $host = null, bool $onlyActive = false, ?string $type = null, callable $callback = null): \Generator
    {
        $query = $this->buildQuery($onlyActive, $type);

        if ($host !== null) {
            $query->andWhere('r.host = :host')
                ->setParameter('host', $host);
        }

        return $this->iterate($query->getQuery()->iterate(), $callback);
    }

    /**
     * Finds all redirects without a host and filtered by the parameters and returns an IterableResult
     *
     * @param bool $onlyActive Filters redirects which start and end datetime match the current datetime
     * @param string|null $type Filters redirects by their type
     * @param callable $callback
     * @return \Generator<Redirect>
     * @throws \Exception
     */
    public function findAllWithoutHost(bool $onlyActive = false, ?string $type = null, callable $callback = null): \Generator
    {
        $query = $this->buildQuery($onlyActive, $type);
        $query->andWhere('r.host IS NULL');

        return $this->iterate($query->getQuery()->iterate(), $callback);
    }

    /**
     * @return void
     */
    public function removeAll(): void
    {
        /** @var Query $query */
        $query = $this->entityManager->createQuery('DELETE Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect r');
        $query->execute();
    }

    /**
     * @param string|null $host
     * @return void
     */
    public function removeByHost(?string $host = null): void
    {
        /** @var Query $query */
        if ($host === null) {
            $query = $this->entityManager->createQuery('DELETE Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect r WHERE r.host IS NULL');
        } else {
            $query = $this->entityManager->createQuery('DELETE Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect r WHERE r.host = :host');
            $query->setParameter(':host', $host);
        }
        $query->execute();
    }

    /**
     * Return a list of all hosts
     *
     * @return array
     */
    public function findDistinctHosts(): array
    {
        /** @var Query $query */
        $query = $this->entityManager->createQuery('SELECT DISTINCT r.host FROM Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect r');
        return array_map(function ($record) {
            return $record['host'];
        }, $query->getResult());
    }

    /**
     * @param RedirectInterface $redirect
     * @return void
     */
    public function incrementHitCount(RedirectInterface $redirect): void
    {
        $host = $redirect->getHost();
        /** @var Query $query */
        if ($host === null) {
            $query = $this->entityManager->createQuery('UPDATE Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect r SET r.hitCounter = r.hitCounter + 1, r.lastHit = CURRENT_TIMESTAMP() WHERE r.sourceUriPath = :sourceUriPath and r.host IS NULL');
        } else {
            $query = $this->entityManager->createQuery('UPDATE Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect r SET r.hitCounter = r.hitCounter + 1, r.lastHit = CURRENT_TIMESTAMP() WHERE r.sourceUriPath = :sourceUriPath and r.host = :host');
            $query->setParameter('host', $host);
        }
        $query->setParameter('sourceUriPath', $redirect->getSourceUriPath())
            ->execute();
    }

    /**
     * Iterator over an IterableResult and return a Generator
     *
     * @param IterableResult $iterator
     * @param callable $callback
     * @return \Generator<RedirectDto>
     */
    protected function iterate(IterableResult $iterator, callable $callback = null): \Generator
    {
        $iteration = 0;
        foreach ($iterator as $object) {
            /** @var Redirect $object */
            $object = current($object);
            yield $object;
            if ($callback !== null) {
                call_user_func($callback, $iteration, $object);
            }
            $iteration++;
        }
    }

    /**
     * Persists all entities managed by the repository and all cascading dependencies
     *
     * @return void
     */
    public function persistEntities(): void
    {
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap() as $className => $entities) {
            if ($className === $this->entityClassName) {
                foreach ($entities as $entityToPersist) {
                    $this->entityManager->flush($entityToPersist);
                }
                $this->emitRepositoryObjectsPersisted();
                break;
            }
        }
    }

    /**
     * Signals that persistEntities() in this repository finished correctly.
     *
     * @Flow\Signal
     * @return void
     */
    protected function emitRepositoryObjectsPersisted(): void
    {
    }
}
