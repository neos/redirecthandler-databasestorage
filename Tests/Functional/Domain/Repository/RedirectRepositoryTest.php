<?php
namespace Neos\RedirectHandler\DatabaseStorage\Tests\Functional\Domain\Repository;

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
 * Functional tests for the RedirectRepository and dependant classes
 */
class RedirectRepositoryTest extends FunctionalTestCase
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
    public function incrementHitcounter()
    {
        $sourceHost = 'example.org';
        $sourcePath = 'some/old/product';
        $oldTargetUri = "https://www.$sourceHost/productA";

        // create a new redirect entry
        $this->redirectStorage->addRedirect($sourcePath, $oldTargetUri);
        // and persist it
        $this->redirectRepository->persistEntities();

        // query the redirect model
        $absoluteRedirect = $this->redirectRepository->findOneBySourceUriPathAndHost($sourcePath);
        // save the hitcounter
        $oldHitcounter = $absoluteRedirect->getHitCounter();

        // increment the hitcounter
        $this->redirectRepository->incrementHitCount($absoluteRedirect);

        // clearState forces the persistenceManager to fetch the redirect from the database
        $this->persistenceManager->clearState();

        // query the hitcounter again
        $absoluteRedirect = $this->redirectRepository->findOneBySourceUriPathAndHost($sourcePath);

        // check if old hitcounter+1 equals the new hitcounter
        $this->assertSame($oldHitcounter+1, $absoluteRedirect->getHitCounter());
    }
}
