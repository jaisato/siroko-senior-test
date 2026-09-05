<?php

declare(strict_types=1);

namespace Siroko;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * The framework logger appends to a file under this directory (see
     * config/services.yaml) and, unlike Monolog, does not create it; on a fresh
     * checkout the first console command would otherwise fail to boot.
     */
    public function getLogDir(): string
    {
        $dir = parent::getLogDir();

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir;
    }
}
