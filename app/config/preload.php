<?php

declare(strict_types=1);

// The container class is derived from the kernel's FQCN (Siroko\Kernel), not
// from the App\ namespace the skeleton assumed - the previous path never existed,
// so preloading silently did nothing in production.
if (file_exists(dirname(__DIR__) . '/var/cache/prod/Siroko_KernelProdContainer.preload.php')) {
    require dirname(__DIR__) . '/var/cache/prod/Siroko_KernelProdContainer.preload.php';
}
