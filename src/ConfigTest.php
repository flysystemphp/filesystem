<?php

declare(strict_types=1);

namespace Flysystem;

use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @test
     */
    public function a_config_object_exposes_passed_options(): void
    {
        $config = new Config(['option' => 'value']);
        $this->assertEquals('value', $config->get('option'));
    }

    /**
     * @test
     */
    public function a_config_object_returns_a_default_value(): void
    {
        $config = new Config();

        $this->assertNull($config->get('option'));
        $this->assertEquals('default', $config->get('option', 'default'));
    }

    /**
     * @test
     */
    public function extending_a_config_with_options(): void
    {
        $config = new Config(['option' => 'value', 'first' => 1]);
        $extended = $config->extend(['option' => 'overwritten', 'second' => 2]);

        $this->assertEquals('overwritten', $extended->get('option'));
        $this->assertEquals(1, $extended->get('first'));
        $this->assertEquals(2, $extended->get('second'));
    }
}
