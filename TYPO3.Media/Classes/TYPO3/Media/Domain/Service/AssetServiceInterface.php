<?php
namespace TYPO3\Media\Domain\Service;

/*
 * This file is part of the TYPO3.Media package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use TYPO3\Media\Domain\Model\AssetInterface;

/**
 * Interface for asset services.
 */
interface AssetServiceInterface
{
    /**
     * Add an asset object.
     *
     * @param AssetInterface $asset
     * @return void
     * @api
     */
    public function add(AssetInterface $asset);

    /**
     * Update an asset object.
     *
     * @param AssetInterface $asset
     * @return void
     * @api
     */
    public function update(AssetInterface $asset);

    /**
     * Deletes an asset.
     *
     * @param AssetInterface $asset
     * @return void
     * @api
     */
    public function remove(AssetInterface $asset);

    /**
     * Returns an array of usage reference objects.
     *
     * @param AssetInterface $asset
     * @return array<\TYPO3\Media\Domain\Model\Dto\UsageReference>
     * @api
     */
    public function getUsageReferences(AssetInterface $asset);

    /**
     * Return the total count of usages of the asset based on the strategy.
     *
     * @param AssetInterface $asset
     * @return integer
     * @api
     */
    public function getUsageCount(AssetInterface $asset);

    /**
     * Returns if the asset is in use following the strategy.
     *
     * @param AssetInterface $asset
     * @return boolean
     * @api
     */
    public function isInUse(AssetInterface $asset);
}
