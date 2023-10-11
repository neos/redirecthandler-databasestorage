<?php

namespace Neos\RedirectHandler\DatabaseStorage\Tests\Functional;

/*
 * This file is part of the Neos.RedirectHandler.DatabaseStorage package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Tests\FunctionalTestCase;
use Neos\RedirectHandler\DatabaseStorage\Domain\Repository\RedirectRepository;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;

/**
 * Functional tests for the RedirectService and dependant classes
 */
class RedirectStorageTest extends FunctionalTestCase
{
    /**
     * @var boolean
     */
    protected static $testablePersistenceEnabled = true;

    /**
     * @var RedirectStorageInterface
     */
    protected $redirectStorage;

    /**
     * @var RedirectRepository
     */
    protected $redirectRepository;

    public function setUp(): void
    {
        parent::setUp();
        $this->redirectStorage = $this->objectManager->get(RedirectStorageInterface::class);
        $this->redirectRepository = $this->objectManager->get(RedirectRepository::class);
    }

    /**
     * @test
     */
    public function addRedirectResolvesRedirectChainsWithRelativeTargetUris()
    {
        $sourcePath = 'some/old/product';
        $oldTargetUri = 'productB';
        $newTargetUri = 'product/B';

        $this->redirectStorage->addRedirect($sourcePath, $oldTargetUri);
        $this->redirectRepository->persistEntities();

        $this->redirectStorage->addRedirect($oldTargetUri, $newTargetUri);

        $relativeRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourcePath);
        $this->assertSame($newTargetUri, $relativeRedirect->getTargetUriPath());
    }

    /**
     * @test
     */
    public function addRedirectResolvesRedirectChainsWithAbsoluteTargetUris()
    {
        $sourceHost = 'www.example.org';
        $sourcePath = 'some/old/product';
        $oldTargetUri = "https://$sourceHost/productA";
        $newTargetUri = 'product/A';
        $newAbsoluteTargetUri = "https://$sourceHost/$newTargetUri";

        $this->redirectStorage->addRedirect($sourcePath, $oldTargetUri);
        $this->redirectRepository->persistEntities();

        $this->redirectStorage->addRedirect('productA', $newTargetUri);

        $absoluteRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourcePath);

        $this->assertSame($oldTargetUri, $absoluteRedirect->getTargetUriPath());

        $this->redirectStorage->addRedirect('productA', $newTargetUri, null, [$sourceHost]);

        $absoluteRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourcePath);
        $this->assertSame($newAbsoluteTargetUri, $absoluteRedirect->getTargetUriPath());
    }

    /**
     * @test
     */
    public function addRedirectDoesNotModifyRedirectsWithSimilarHost()
    {
        $sourceHost = 'example.org';
        $sourcePath = 'some/old/product';
        $oldTargetUri = "https://www.$sourceHost/productA";
        $newTargetUri = 'product/A';

        $this->redirectStorage->addRedirect($sourcePath, $oldTargetUri);
        $this->redirectRepository->persistEntities();

        $this->redirectStorage->addRedirect('productA', $newTargetUri, null, [$sourceHost]);

        $absoluteRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourcePath);

        $this->assertSame($oldTargetUri, $absoluteRedirect->getTargetUriPath());
    }

    /**
     * @test
     */
    public function updateStatusCodeIfObsoleteRedirectGotUpdated()
    {
        $sourcePath = 'some/old/product';

        $oldTargetUri = 'productB';
        $oldStatusCode = 301;

        $newTargetUri = '';
        $newStatusCode = 410;

        $this->redirectStorage->addRedirect($sourcePath, $oldTargetUri, $oldStatusCode);
        $this->redirectRepository->persistEntities();

        $this->redirectStorage->addRedirect($oldTargetUri, $newTargetUri, $newStatusCode);

        $relativeRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourcePath);
        $this->assertSame($newTargetUri, $relativeRedirect->getTargetUriPath());
        $this->assertSame($newStatusCode, $relativeRedirect->getStatusCode());
    }

    /**
     * @test
     */
    public function updateStatusCodeIfObsoleteRedirectWithAbsoluteUriGotUpdated()
    {
        $sourceHost = 'www.example.org';
        $sourcePath = 'some/old/product';

        $oldTargetUri = "https://$sourceHost/productA";
        $oldStatusCode = 301;

        $newTargetUri = 'product/A';

        $newAbsoluteTargetUri = "https://$sourceHost/$newTargetUri";
        $newAbsoluteStatusCode = 399;

        $this->redirectStorage->addRedirect($sourcePath, $oldTargetUri, $oldStatusCode);
        $this->redirectRepository->persistEntities();

        $this->redirectStorage->addRedirect('productA', $newTargetUri);
        $this->redirectRepository->persistEntities();

        $absoluteRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourcePath);

        $this->assertSame($oldTargetUri, $absoluteRedirect->getTargetUriPath());
        $this->assertSame($oldStatusCode, $absoluteRedirect->getStatusCode());

        $this->redirectStorage->addRedirect('productA', $newTargetUri, $newAbsoluteStatusCode, [$sourceHost]);
        $this->redirectRepository->persistEntities();

        $absoluteRedirect = $this->redirectStorage->getOneBySourceUriPathAndHost($sourcePath);
        $this->assertSame($newAbsoluteTargetUri, $absoluteRedirect->getTargetUriPath());
        $this->assertSame($newAbsoluteStatusCode, $absoluteRedirect->getStatusCode());
    }
}
