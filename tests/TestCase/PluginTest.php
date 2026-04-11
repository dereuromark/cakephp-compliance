<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase;

use Compliance\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Sanity-check that the main plugin class loads.
 */
class PluginTest extends TestCase
{
    public function testPluginCanBeInstantiated(): void
    {
        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);
    }
}
