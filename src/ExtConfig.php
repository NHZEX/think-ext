<?php

namespace Zxin\Think\Ext;

class ExtConfig
{
    /**
     * @var ExtConfig
     */
    private static $instance;

    /**
     * @var string|null
     */
    protected $builtCaFilePath;

    public static function getInstance(): ExtConfig
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function __construct()
    {
    }

    /**
     * @param string|null $caRootPath
     */
    public function setBuiltCaFilePath(?string $caRootPath): void
    {
        $this->builtCaFilePath = $caRootPath;
    }

    /**
     * @return string|null
     */
    public function getBuiltCaFilePath(): ?string
    {
        return $this->builtCaFilePath;
    }
}
