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
