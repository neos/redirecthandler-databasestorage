<?php
namespace Neos\RedirectHandler\DatabaseStorage;

/*
 * This file is part of the Neos.RedirectHandler.DatabaseStorage package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use DateTime;
use Generator;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect;
use Neos\RedirectHandler\DatabaseStorage\Domain\Repository\RedirectRepository;
use Neos\RedirectHandler\Exception;
use Neos\RedirectHandler\Redirect as RedirectDto;
use Neos\RedirectHandler\RedirectInterface;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Neos\RedirectHandler\Traits\RedirectSignalTrait;
use Neos\RedirectHandler\RedirectService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\RouterCachingService;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * Database Storage for the Redirects.
 *
 * @Flow\Scope("singleton")
 */
class RedirectStorage implements RedirectStorageInterface
{
    use RedirectSignalTrait;

    /**
     * @Flow\Inject
     *
     * @var RedirectRepository
     */
    protected $redirectRepository;

    /**
     * @Flow\Inject
     *
     * @var RouterCachingService
     */
    protected $routerCachingService;

    /**
     * @Flow\InjectConfiguration(path="statusCode", package="Neos.RedirectHandler")
     *
     * @var array
     */
    protected $defaultStatusCode;

    /**
     * @Flow\Inject
     *
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * {@inheritdoc}
     */
    public function getOneBySourceUriPathAndHost($sourceUriPath, $host = null, $fallback = true): ?RedirectInterface
    {
        $redirect = $this->redirectRepository->findOneBySourceUriPathAndHost($sourceUriPath, $host, $fallback);
        if ($redirect !== null) {
            return RedirectDto::create($redirect);
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getAll($host = null): Generator
    {
        foreach ($this->redirectRepository->findAll($host) as $redirect) {
            yield RedirectDto::create($redirect);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getDistinctHosts(): array
    {
        return $this->redirectRepository->findDistinctHosts();
    }

    /**
     * {@inheritdoc}
     * @throws IllegalObjectTypeException
     */
    public function removeOneBySourceUriPathAndHost($sourceUriPath, $host = null): void
    {
        $redirect = $this->redirectRepository->findOneBySourceUriPathAndHost($sourceUriPath, $host);
        if ($redirect === null) {
            return;
        }
        $this->redirectRepository->remove($redirect);
    }

    /**
     * {@inheritdoc}
     */
    public function removeAll(): void
    {
        $this->redirectRepository->removeAll();
    }

    /**
     * {@inheritdoc}
     */
    public function removeByHost($host = null): void
    {
        $this->redirectRepository->removeByHost($host);
    }

    /**
     * {@inheritdoc}
     * @throws IllegalObjectTypeException
     * @throws Exception
     */
    public function addRedirect(
        $sourceUriPath,
        $targetUriPath,
        $statusCode = null,
        array $hosts = [],
        $creator = null,
        $comment = null,
        $type = null,
        DateTime $startDateTime = null,
        DateTime $endDateTime = null
    ): array {
        $statusCode = $statusCode ?: (int)$this->defaultStatusCode['redirect'];
        $redirects = [];
        if ($hosts !== []) {
            array_map(function ($host) use (
                $sourceUriPath,
                $targetUriPath,
                $statusCode,
                $creator,
                $comment,
                $type,
                $startDateTime,
                $endDateTime,
                &$redirects
            ) {
                $redirects[] = $this->addRedirectByHost(
                    $sourceUriPath,
                    $targetUriPath,
                    $statusCode,
                    $host,
                    $creator,
                    $comment,
                    $type,
                    $startDateTime,
                    $endDateTime);
            }, $hosts);
        } else {
            $redirects[] = $this->addRedirectByHost(
                $sourceUriPath,
                $targetUriPath,
                $statusCode,
                [],
                $creator,
                $comment,
                $type,
                $startDateTime,
                $endDateTime);
        }
        $this->emitRedirectCreated($redirects);

        return $redirects;
    }

    /**
     * Adds a redirect to the repository and updates related redirects accordingly.
     *
     * @param string $sourceUriPath the relative URI path that should trigger a redirect
     * @param string $targetUriPath the relative URI path the redirect should point to
     * @param int $statusCode the status code of the redirect header
     * @param string|null $host the host for the current redirect
     * @param string|null $creator
     * @param string|null $comment
     * @param string|null $type
     * @param DateTime|null $startDateTime
     * @param DateTime|null $endDateTime
     * @return RedirectInterface the freshly generated redirect DTO instance
     * @throws IllegalObjectTypeException
     * @throws \Exception
     * @api
     */
    protected function addRedirectByHost(
        $sourceUriPath,
        $targetUriPath,
        $statusCode,
        $host = null,
        $creator = null,
        $comment = null,
        $type = null,
        DateTime $startDateTime = null,
        DateTime $endDateTime = null
    ): RedirectInterface {
        $redirect = new Redirect($sourceUriPath, $targetUriPath, $statusCode, $host, $creator, $comment, $type,
            $startDateTime, $endDateTime);
        $this->updateDependingRedirects($redirect);
        $this->persistenceManager->persistAll();
        $this->redirectRepository->add($redirect);
        $this->routerCachingService->flushCachesForUriPath($sourceUriPath);

        return RedirectDto::create($redirect);
    }

    /**
     * Updates affected redirects in order to avoid redundant or circular redirections.
     *
     * @param RedirectInterface $newRedirect
     * @return void
     * @throws IllegalObjectTypeException
     * @throws \Exception
     */
    protected function updateDependingRedirects(RedirectInterface $newRedirect): void
    {
        /** @var $existingRedirectForSourceUriPath Redirect */
        $existingRedirectForSourceUriPath = $this->redirectRepository->findOneBySourceUriPathAndHost($newRedirect->getSourceUriPath(),
            $newRedirect->getHost(), false);
        if ($existingRedirectForSourceUriPath !== null) {
            $this->removeAndLog($existingRedirectForSourceUriPath,
                sprintf('Existing redirect for the source URI path "%s" removed.', $newRedirect->getSourceUriPath()));
            $this->routerCachingService->flushCachesForUriPath($existingRedirectForSourceUriPath->getSourceUriPath());
        }

        /** @var $existingRedirectForTargetUriPath Redirect */
        $existingRedirectForTargetUriPath = $this->redirectRepository->findOneBySourceUriPathAndHost($newRedirect->getTargetUriPath(),
            $newRedirect->getHost(), false);
        if ($existingRedirectForTargetUriPath !== null) {
            $this->removeAndLog($existingRedirectForTargetUriPath,
                sprintf('Existing redirect for the target URI path "%s" removed.', $newRedirect->getTargetUriPath()));
            $this->routerCachingService->flushCachesForUriPath($existingRedirectForTargetUriPath->getSourceUriPath());
        }

        $obsoleteRedirectInstances = $this->redirectRepository->findByTargetUriPathAndHost($newRedirect->getSourceUriPath(),
            $newRedirect->getHost());
        /** @var $obsoleteRedirect Redirect */
        foreach ($obsoleteRedirectInstances as $obsoleteRedirect) {
            if ($obsoleteRedirect->getSourceUriPath() === $newRedirect->getTargetUriPath()) {
                $this->redirectRepository->remove($obsoleteRedirect);
            } else {
                $obsoleteRedirect->setTargetUriPath($newRedirect->getTargetUriPath());
                $this->redirectRepository->update($obsoleteRedirect);
            }
        }
    }

    /**
     * @param RedirectInterface $redirect
     * @param string $message
     * @return void
     * @throws IllegalObjectTypeException
     */
    protected function removeAndLog(RedirectInterface $redirect, $message): void
    {
        $this->redirectRepository->remove($redirect);
        $this->redirectRepository->persistEntities();
        $this->_logger->log($message, LOG_NOTICE);
    }

    /**
     * Increment the hit counter for the given redirect.
     *
     * @param RedirectInterface $redirect
     * @return void
     * @api
     */
    public function incrementHitCount(RedirectInterface $redirect): void
    {
        try {
            $this->redirectRepository->incrementHitCount($redirect);
        } catch (\Exception $exception) {
            $this->_logger->logException($exception);
        }
    }
}
