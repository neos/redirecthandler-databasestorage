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

use Neos\RedirectHandler\DatabaseStorage\Domain\Model\Redirect;
use Neos\RedirectHandler\DatabaseStorage\Domain\Repository\RedirectRepository;
use Neos\RedirectHandler\Exception;
use Neos\RedirectHandler\Redirect as RedirectDto;
use Neos\RedirectHandler\RedirectInterface;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use Neos\RedirectHandler\Traits\RedirectSignalTrait;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Mvc\Routing\RouterCachingService;

/**
 * Database Storage for the Redirects
 *
 * @Flow\Scope("singleton")
 */
class RedirectStorage implements RedirectStorageInterface
{
    use RedirectSignalTrait;

    /**
     * @Flow\Inject
     * @var RedirectRepository
     */
    protected $redirectRepository;

    /**
     * @Flow\Inject
     * @var RouterCachingService
     */
    protected $routerCachingService;

    /**
     * @Flow\InjectConfiguration(path="statusCode", package="Neos.RedirectHandler")
     * @var array
     */
    protected $defaultStatusCode;

    /**
     * {@inheritdoc}
     */
    public function getOneBySourceUriPathAndHost($sourceUriPath, $host = null, $fallback = true)
    {
        $redirect = $this->redirectRepository->findOneBySourceUriPathAndHost($sourceUriPath, $host, $fallback);
        if ($redirect === null) {
            return null;
        }
        return RedirectDto::create($redirect);
    }

    /**
     * {@inheritdoc}
     */
    public function getAll($host = null)
    {
        foreach ($this->redirectRepository->findAll($host) as $redirect) {
            yield RedirectDto::create($redirect);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getDistinctHosts()
    {
        return $this->redirectRepository->findDistinctHosts();
    }

    /**
     * {@inheritdoc}
     */
    public function removeOneBySourceUriPathAndHost($sourceUriPath, $host = null)
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
    public function removeAll()
    {
        $this->redirectRepository->removeAll();
    }

    /**
     * {@inheritdoc}
     */
    public function removeByHost($host = null)
    {
        $this->redirectRepository->removeByHost($host);
    }

    /**
     * {@inheritdoc}
     * @throws Exception if there already exists a different redirect for the source uri path
     */
    public function addRedirect($sourceUriPath, $targetUriPath, $statusCode = null, array $hosts = [])
    {
        $statusCode = $statusCode ?: (integer)$this->defaultStatusCode['redirect'];
        $redirects = [];
        if ($hosts !== []) {
            array_map(function ($host) use ($sourceUriPath, $targetUriPath, $statusCode, &$redirects) {
                $redirects[] = $this->addRedirectByHost($sourceUriPath, $targetUriPath, $statusCode, $host);
            }, $hosts);
        } else {
            $redirects[] = $this->addRedirectByHost($sourceUriPath, $targetUriPath, $statusCode);
        }
        $this->emitRedirectCreated($redirects);
        return $redirects;
    }

    /**
     * Adds a redirect to the repository and updates related redirects accordingly
     *
     * @param string $sourceUriPath the relative URI path that should trigger a redirect
     * @param string $targetUriPath the relative URI path the redirect should point to
     * @param integer $statusCode the status code of the redirect header
     * @param string $host the host for the current redirect
     *
     * @return RedirectInterface the freshly generated redirect DTO instance or the already existing redirect as DTO
     * @throws Exception if there already exists a different redirect for the source uri and host
     * @api
     */
    protected function addRedirectByHost($sourceUriPath, $targetUriPath, $statusCode, $host = null)
    {
        $redirect = new Redirect($sourceUriPath, $targetUriPath, $statusCode, $host);
        $existingRedirect = $this->getOneBySourceUriPathAndHost($sourceUriPath, $host, false);
        if ($existingRedirect === null) {
            $this->updateDependingRedirects($redirect);
            $this->redirectRepository->add($redirect);
            $this->routerCachingService->flushCachesForUriPath($sourceUriPath);
            return RedirectDto::create($redirect);
        } elseif ($existingRedirect->getTargetUriPath() === $redirect->getTargetUriPath()
            && $existingRedirect->getStatusCode() === $redirect->getStatusCode()
        ) {
            return RedirectDto::create($existingRedirect);
        } else {
            throw new Exception('There already exists a redirect with status code "' . $existingRedirect->getStatusCode() . '" for the source uri path "' . $redirect->getSourceUriPath() . '" to "' . $existingRedirect->getTargetUriPath() . '" ' . ($host ? ' on host "' . $host . '"' : '') . '. Could not create redirect to "' . $redirect->getTargetUriPath() . '" with status code "' . $redirect->getStatusCode() . '".', 201609271250);
        }
    }

    /**
     * Updates affected redirects in order to avoid redundant or circular redirects
     *
     * @param RedirectInterface $newRedirect
     * @return void
     * @throws Exception if creating the redirect would cause conflicts
     */
    protected function updateDependingRedirects(RedirectInterface $newRedirect)
    {
        $existingRedirectForSourceUriPath = $this->redirectRepository->findOneBySourceUriPathAndHost($newRedirect->getSourceUriPath(), $newRedirect->getHost(), false);
        if ($existingRedirectForSourceUriPath !== null) {
            $this->removeAndLog($existingRedirectForSourceUriPath, sprintf('Existing redirect for the source URI path "%s" removed.', $newRedirect->getSourceUriPath()));
            $this->routerCachingService->flushCachesForUriPath($existingRedirectForSourceUriPath->getSourceUriPath());
        }

        $existingRedirectForTargetUriPath = $this->redirectRepository->findOneBySourceUriPathAndHost($newRedirect->getTargetUriPath(), $newRedirect->getHost(), false);
        if ($existingRedirectForTargetUriPath !== null) {
            $this->removeAndLog($existingRedirectForTargetUriPath, sprintf('Existing redirect for the target URI path "%s" removed.', $newRedirect->getTargetUriPath()));
            $this->routerCachingService->flushCachesForUriPath($existingRedirectForTargetUriPath->getSourceUriPath());
        }

        $obsoleteRedirectInstances = $this->redirectRepository->findByTargetUriPathAndHost($newRedirect->getSourceUriPath(), $newRedirect->getHost());
        /** @var $obsoleteRedirect Redirect */
        foreach ($obsoleteRedirectInstances as $obsoleteRedirect) {
            if ($obsoleteRedirect->getSourceUriPath() === $newRedirect->getTargetUriPath()) {
                $this->redirectRepository->remove($obsoleteRedirect);
            } else {
                $obsoleteRedirect->setTargetUriPath($newRedirect->getTargetUriPath());
                $this->redirectRepository->update($obsoleteRedirect);
                $this->routerCachingService->flushCachesForUriPath($obsoleteRedirect->getSourceUriPath());
            }
        }
    }

    /**
     * @param RedirectInterface $redirect
     * @param string $message
     * @return void
     */
    protected function removeAndLog(RedirectInterface $redirect, $message)
    {
        $this->redirectRepository->remove($redirect);
        $this->redirectRepository->persistEntities();
        $this->_logger->log($message, LOG_NOTICE);
    }

    /**
     * Increment the hit counter for the given redirect
     *
     * @param RedirectInterface $redirect
     * @return void
     * @api
     */
    public function incrementHitCount(RedirectInterface $redirect)
    {
        try {
            $this->redirectRepository->incrementHitCount($redirect);
        } catch (\Exception $exception) {
            $this->_logger->logException($exception);
        }
    }
}
