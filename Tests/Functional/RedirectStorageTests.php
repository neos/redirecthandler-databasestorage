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
class RedirectStorageTests extends FunctionalTestCase
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
}
