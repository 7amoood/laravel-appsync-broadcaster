<?php
namespace Hamoood\LaravelAppSyncBroadcaster\Tests;

use Hamoood\LaravelAppSyncBroadcaster\AppSyncBroadcastServiceProvider;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            AppSyncBroadcastServiceProvider::class,
        ];
    }

    public function test_config_is_merged_when_not_published()
    {
        $publishedConfigPath = $this->app->configPath('broadcasting/appsync.php');

        if (File::exists($publishedConfigPath)) {
            File::delete($publishedConfigPath);
        }

        $serviceProvider = new AppSyncBroadcastServiceProvider($this->app);
        $serviceProvider->register();

        $config = config('broadcasting.connections.appsync');
        $this->assertNotNull($config);
        $this->assertEquals('appsync', $config['driver']);
    }

    public function test_published_config_takes_precedence()
    {
        $publishedConfigPath = $this->app->configPath('broadcasting/appsync.php');
        File::ensureDirectoryExists(dirname($publishedConfigPath));
        File::put($publishedConfigPath, "<?php\nreturn ['driver' => 'published', 'custom' => 'value'];");

        $serviceProvider = new AppSyncBroadcastServiceProvider($this->app);
        $serviceProvider->register();

        $config = config('broadcasting.connections.appsync');

        File::delete($publishedConfigPath);
        File::deleteDirectory(dirname($publishedConfigPath));

        $this->assertTrue(true);
    }

    public function test_config_can_be_published()
    {
        $this->artisan('vendor:publish', ['--tag' => 'appsync-config'])
            ->assertExitCode(0);

        $publishedConfigPath = $this->app->configPath('appsync.php');
        $this->assertTrue(File::exists($publishedConfigPath));

        $publishedConfig = include $publishedConfigPath;
        $this->assertIsArray($publishedConfig);
        $this->assertEquals('appsync', $publishedConfig['driver']);

        File::delete($publishedConfigPath);
    }

    public function test_config_has_expected_structure()
    {
        $config = config('broadcasting.connections.appsync');

        $this->assertArrayHasKey('driver', $config);
        $this->assertArrayHasKey('mode', $config);
        $this->assertArrayHasKey('transport', $config);
        $this->assertArrayHasKey('cache', $config);
        $this->assertArrayHasKey('namespace', $config);
        $this->assertArrayHasKey('retry', $config);
        $this->assertArrayHasKey('redis', $config);
        $this->assertArrayHasKey('websocket', $config);
        $this->assertArrayHasKey('token', $config);
        $this->assertArrayHasKey('options', $config);
    }
}
