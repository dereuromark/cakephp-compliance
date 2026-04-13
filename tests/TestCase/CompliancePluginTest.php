<?php

declare(strict_types=1);

namespace Compliance\Test\TestCase;

use Compliance\CompliancePlugin;
use PHPUnit\Framework\TestCase;

/**
 * Sanity-check that the main plugin class loads.
 */
class CompliancePluginTest extends TestCase
{
    public function testCompliancePluginCanBeInstantiated(): void
    {
        $plugin = new CompliancePlugin();
        $this->assertInstanceOf(CompliancePlugin::class, $plugin);
    }
}
