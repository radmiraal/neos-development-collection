Developer Information
=====================

AssetService
------------

Direct usage of the asset repositories for write actions like ``add``, ``update`` or
``remove`` is discouraged. It's strongly advised to use the AssetService for doing so.
You can get your instance of the configured asset service by using the following snippet:

.. code-block:: php

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Media\Domain\Service\AssetServiceInterface
	 */
	protected $assetService;

Asset Usage Strategies
----------------------

It is possible to extend the media handling by defining asset usage strategies. Those
strategies can tell the media package if an asset is in used, how many times it is
used and how it is used.

To define your own custom usage strategy you have to implement the
``TYPO3\Media\Domain\Strategy\AssetUsageStrategyInterface``. For convenience you can
extend the ``TYPO3\Media\Domain\Strategy\AbstractAssetUsageStrategy``.

.. note::

    The usage strategies are only in effect for write actions done via the AssetService.
    Direct asset repository usage is excluded from the strategies.

Example Strategy
****************

.. code-block:: php

	use TYPO3\Flow\Annotations as Flow;
	use TYPO3\Media\Domain\Strategy\AbstractAssetUsageStrategy;
	use TYPO3\Media\Domain\Strategy\AssetUsageStrategyInterface;
	use TYPO3\Flow\Persistence\PersistenceManagerInterface;

	/**
	 * @Flow\Scope("singleton")
	 */
	class MyCustomAssetUsageStrategy extends AbstractAssetUsageStrategy implements AssetUsageStrategyInterface
	{
	    /**
	     * @Flow\Inject
	     * @var PersistenceManagerInterface
	     */
	    protected $persistenceManager;

	    /**
	     * @var array
	     */
	    protected $firstlevelCache = [];

	    /**
	     * Returns an array of usage reference objects.
	     *
	     * @param AssetInterface $asset
	     * @return array<\TYPO3\Media\Domain\Model\Dto\UsageReference>
	     */
	    public function getUsageReferences(AssetInterface $asset)
	    {
	        $assetIdentifier = $this->persistenceManager->getIdentifierByObject($asset);
	        if (isset($this->firstlevelCache[$assetIdentifier])) {
	            return $this->firstlevelCache[$assetIdentifier];
	        }

	        // Your code to find asset usage
	        foreach ($usages as $usage) {
	            $this->firstlevelCache[$assetIdentifier] = new \TYPO3\Media\Domain\Model\Dto\UsageReference($asset);
	        }

	        return $this->firstlevelCache[$assetIdentifier];
	    }
	}
