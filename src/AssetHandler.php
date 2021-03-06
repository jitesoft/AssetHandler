<?php
/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
  AssetHandler.php - Part of the AssetHandler project.

  File created by Johannes Tegnér at 2016-08-08 - kl 15:20
  © - 2016
 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */
namespace Jitesoft\AssetHandler;

use Jitesoft\AssetHandler\Internal\Contracts\AssetHandlerInterface;
use Jitesoft\AssetHandler\Internal\Exceptions\ {
    AssetNameNotUniqueException,
    InvalidAssetException,
    InvalidContainerException,
    InvalidPathException,

    ExceptionMessages as Errors
};
use Jitesoft\AssetHandler\Internal\Asset;
use Jitesoft\AssetHandler\Internal\AssetContainer;

class AssetHandler implements AssetHandlerInterface {

    /** @var array|AssetContainer[] */
    private $containers = array();

    public function __construct() {

        if (function_exists('config')) {
            $containers = \config('asset-handler.containers');
        } else {
            $containers = require __DIR__ . '/../config/asset-handler.php';
            $containers = $containers['containers'];
        }

        foreach ((array)$containers as $type => $data) {

            $this->containers[$type] = new AssetContainer(
                $type,
                $data['url'],
                $data['path'],
                $data['print_pattern'],
                isset($data['file_regex']) ? $data['file_regex'] : null,
                isset($data['versioned']) ? $data['versioned'] : false
            );
        }
    }

    /**
     * @param string $container
     * @return bool
     */
    private function containerExists(string $container) : bool {
        return array_key_exists($container, $this->containers);
    }

    /**
     * @param string $fileName
     * @return string|null
     */
    private function determineContainer(string $fileName) {

        foreach ($this->containers as $type => $container) {
            if (null === $container->getFileRegex()) {
                continue;
            }

            if (preg_match($container->getFileRegex(), $fileName) === 1) {
                return $type;
            }
        }
        return null;
    }

    /**
     * @param string $assetName
     * @param        $containers
     * @return Asset|null
     */
    private function findAssetByName(string $assetName, $containers) {
        foreach ($containers as $cType) {
            $result = $this->containers[$cType]->find(function(Asset $asset) use($assetName) {
                $result = $asset->getName() === $assetName;
                return $result;
            });

            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * @param string $assetPath
     * @param        $containers
     * @return Asset|null
     */
    private function findAssetByPath(string $assetPath, $containers) {
        foreach ($containers as $cType) {

            $result = $this->containers[$cType]->find(function(Asset $asset) use($assetPath) {
                $result = $asset->getPath() === $assetPath;
                return $result;
            });

            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /**
     * @inheritdoc
     * @throws AssetNameNotUniqueException
     * @throws InvalidContainerException
     */
    public function add(string $asset, string $assetName = "", string $container = Asset::ASSET_TYPE_ANY) : bool {

        $assetName = $assetName === "" ? $asset : $assetName;

        if ($container === Asset::ASSET_TYPE_ANY) {
            $container = $this->determineContainer($asset);
            if ($container === null) {
                throw new InvalidContainerException(sprintf(Errors::CONTAINER_NOT_DETERMINABLE, $asset));
            }
        }

        if (!$this->containerExists($container)) {
            throw new InvalidContainerException(sprintf(Errors::CONTAINER_NOT_EXIST, $container));
        }

        $exists = $this->findAssetByName($assetName, [$container]);

        if ($exists !== null) {
            throw new AssetNameNotUniqueException(sprintf(Errors::ASSET_NOT_CONTAINER_UNIQUE, $assetName, $container));
        }

        return $this->containers[$container]->add(new Asset($asset, $assetName));
    }

    /**
     * @inheritdoc
     * @throws AssetNameNotUniqueException
     * @throws InvalidAssetException
     * @throws InvalidContainerException
     */
    public function remove(string $assetName, string $container = Asset::ASSET_TYPE_ANY) : bool {

        if ($container === Asset::ASSET_TYPE_ANY) {
            $container = $this->determineContainer($assetName);

            if ($container === null) {
                throw new InvalidContainerException(sprintf(Errors::CONTAINER_NOT_DETERMINABLE, $assetName));
            }
        }

        if (!$this->containerExists($container)) {
            throw new InvalidContainerException(sprintf(Errors::CONTAINER_NOT_EXIST, $container));
        }

        $has = $this->findAssetByPath($assetName, [$container]) ?? $this->findAssetByName($assetName, [$container]);
        return $has === null ? false : $this->containers[$container]->remove($has);
    }

    /**
     * @inheritdoc
     * @throws InvalidContainerException
     * @throws InvalidPathException
     * @throws InvalidAssetException
     */
    public function printAll(string $container = Asset::ASSET_TYPE_ANY) : string {
        // TODO: This function needs optimisation, calling print() for all the assets is quite dumb.

        $containers = [];
        if ($container !== Asset::ASSET_TYPE_ANY) {
            $containers[] = $container;
        } else {
            foreach ($this->containers as $key => $val) {
                $containers[] = $key;
            }
        }

        $out = "";
        foreach ($containers as $container) {
            foreach ($this->containers[$container] as $asset) {
                /** @var Asset $asset */
                $out .= $this->print(
                    $asset->getName(),
                    $asset->getType(),
                    $this->containers[$container]->getPrintPattern()
                );
            }
        }

        return $out;
    }

    /**
     * @inheritdoc
     */
    public function getAssets(string $container = Asset::ASSET_TYPE_ANY) : array {
        $containers = [];

        if ($container === Asset::ASSET_TYPE_ANY) {
            $containers = array_keys($this->containers);
            $containers = array_map(function(string $container) {
                return $this->containers[$container]->toArray();
            }, $containers);
        } else {
            $containers[] = $this->containers[$container]->toArray();
        }

        return array_merge(... $containers);
    }

    /**
     * @inheritdoc
     * @throws InvalidContainerException
     */
    public function removeContainer(string $containerName) {
        if (!$this->containerExists($containerName)) {
            throw new InvalidContainerException(Errors::CONTAINER_NOT_EXIST, $containerName);
        }

        unset($this->containers[$containerName]);
        return true;
    }

    /**
     * @inheritdoc
     * @throws InvalidContainerException
     */
    public function setBaseUrl(string $url = "/assets", string $container = Asset::ASSET_TYPE_ANY) : bool {

        if ($container === Asset::ASSET_TYPE_ANY) {
            foreach ($this->containers as $container) {
                $container->setBaseUrl($url);
            }
            return true;
        }

        if (!array_key_exists($container, $this->containers)) {
            throw new InvalidContainerException(sprintf(Errors::CONTAINER_NOT_EXIST, $container));
        }

        $this->containers[$container]->setBaseUrl($url);
        return true;
    }

    /**
     * @inheritdoc
     * @throws InvalidContainerException
     * @throws InvalidPathException
     */
    public function setBasePath(string $path = null, string $container = Asset::ASSET_TYPE_ANY) : bool {
        // When setting a path, the bundle needs to access the filesystem to check that the path is actually real.
        // If path is set to null, there will be no FS access.
        $containers = [];

        if ($container === Asset::ASSET_TYPE_ANY) {
            $containers = array_keys($this->containers);
        } else {
            if (!array_key_exists($container, $this->containers)) {
                throw new InvalidContainerException(sprintf(Errors::CONTAINER_NOT_EXIST, $container));
            }
            $containers = [$container];
        }

        // Check path.
        if ($path !== null && !is_dir($path)) {
            throw new InvalidPathException(sprintf(Errors::INVALID_PATH, $path));
        }


        foreach ($containers as $container) {
            $this->containers[$container]->setBasePath($path);
        }

        return true;
    }

    /**
     * @inheritdoc
     * @throws InvalidContainerException
     * @throws InvalidAssetException
     */
    public function print(string $assetName, string $container = Asset::ASSET_TYPE_ANY, string $custom = "") : string {
        $containers = [];

        if ($container === Asset::ASSET_TYPE_ANY) {
            // Try determine container.
            $c = $this->determineContainer($assetName);
            if ($c === null) {
                $containers = array_keys($this->containers);
            } else {
                $containers[] = $c;
            }
        } else {
            $containers[] = $container;
        }

        // Check each container in the array and try find asset by name.

        $exists = $this->findAssetByName($assetName, $containers) ?? $this->findAssetByPath($assetName, $containers);

        if (!$exists) {

            $error = ($container === null || $container === Asset::ASSET_TYPE_ANY) ?
                Errors::ASSET_NOT_EXIST :
                Errors::ASSET_NOT_EXIST_IN_CONTAINER;

            throw new InvalidAssetException(
                sprintf(
                    $error,
                    $assetName,
                    $container
                )
            );
        }

        // If custom is set, that is what is supposed to be used.
        // Else the container array have to contain a pattern for it to work.
        // If it does not, its quite fatal!
        $pattern = $custom;
        if ($pattern === "") {
            if (array_key_exists($exists->getType(), $this->containers)) {
                $pattern = $this->containers[$exists->getType()]->getPrintPattern();
            } else {
                // This should never happen. An asset should always have a container.
                throw new InvalidContainerException(Errors::PRINT_PATTERN_MISSING, $exists->getType());
            }
        }

        $url  = $exists->getFullUrl();
        $path = $this->containers[$exists->getType()]->getBasePath() . $exists->getPath();

        if ($this->containers[$exists->getType()]->isUsingVersioning()) {

            if (!file_exists($path)) {
                throw new InvalidAssetException(
                    sprintf(Errors::INVALID_ASSET_PATH, $exists->getName(), $path)
                );
            }
            $url .= '?' . filemtime($path);
        }

        // Replace the placeholders.
        return str_replace(
                [ "{{PATH}}", "{{URL}}", "{{URI}}", "{{NAME}}" ],
                [ $path, $url, $url, $exists->getName() ],
                $pattern
            ) . PHP_EOL;
    }

    /**
     * @inheritdoc
     * @throws InvalidContainerException
     */
    public function addContainer(string $containerName, string $customTag, string $assetPath = "/public/assets",
                                 string $assetUrl = "/assets", string $fileRegex = null) : bool {

        if ($this->containerExists($containerName)) {
            throw new InvalidContainerException(Errors::CONTAINER_NOT_UNIQUE, $containerName);
        }

        $this->containers[$containerName] = new AssetContainer(
            $containerName,
            $assetUrl,
            $assetPath,
            $customTag,
            $fileRegex
        );

        return true;
    }

    /**
     * @inheritDoc
     */
    public function setIsUsingVersioning(bool $state, string $container = "any") {

        $containers = [];

        if ($container === Asset::ASSET_TYPE_ANY) {
            foreach ($this->containers as $c) {
                $containers[] = $c->getType();
            }
        } else {
            $containers[] = $container;
        }

        foreach ($containers as $container) {
            $this->containers[$container]->setIsUsingVersioning($state);
        }
    }

    /**
     * @inheritDoc
     * @throws InvalidContainerException
     */
    public function isUsingVersioning(string $container) : bool {
        if (!array_key_exists($container, $this->containers)) {
            throw new InvalidContainerException(sprintf(Errors::CONTAINER_NOT_EXIST, $container));
        }

        return $this->containers[$container]->isUsingVersioning();
    }

}
