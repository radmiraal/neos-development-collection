<?php
namespace TYPO3\Neos\Domain\Service;

/*
 * This file is part of the TYPO3.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Persistence\PersistenceManagerInterface;
use TYPO3\Flow\Utility\TypeHandling;
use TYPO3\Media\Domain\Model\AssetInterface;
use TYPO3\Media\Domain\Model\Image;
use TYPO3\Media\Exception\AssetServiceException;
use TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository;

/**
 * The Asset handling Service
 *
 * @Flow\Scope("singleton")
 */
class AssetService extends \TYPO3\Media\Domain\Service\AssetService
{
    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @param AssetInterface $asset
     * @throws AssetServiceException
     * @return void
     */
    public function remove(AssetInterface $asset)
    {
        $relationMap = [];
        $relationMap[TypeHandling::getTypeForValue($asset)] = array($this->persistenceManager->getIdentifierByObject($asset));

        if ($asset instanceof Image) {
            foreach ($asset->getVariants() as $variant) {
                $type = TypeHandling::getTypeForValue($variant);
                if (!isset($relationMap[$type])) {
                    $relationMap[$type] = [];
                }
                $relationMap[$type][] = $this->persistenceManager->getIdentifierByObject($variant);
            }
        }

        $relatedNodes = $this->nodeDataRepository->findNodesByRelatedEntities($relationMap);
        if (count($relatedNodes) > 0) {
            throw new AssetServiceException('Asset could not be deleted, because there are still Nodes using it.', 1462196420);
        }

        parent::remove($asset);
    }
}
