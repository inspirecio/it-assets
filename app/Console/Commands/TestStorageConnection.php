<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TestStorageConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snipeit:test-storage
                            {--disk= : The disk to test (public or private). Defaults to both}
                            {--cleanup : Delete the test file after verification}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connectivity to storage (Digital Ocean Spaces, S3, etc.) by uploading and verifying a test file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $disk = $this->option('disk');
        $cleanup = $this->option('cleanup');

        // Determine which disks to test
        $disksToTest = [];
        if (!$disk || $disk === 'public') {
            $publicDiskDriver = config('filesystems.disks.public.driver');
            $disksToTest['public'] = $publicDiskDriver;
        }
        if (!$disk || $disk === 'private') {
            $privateDiskName = config('filesystems.default');
            $privateDiskDriver = config("filesystems.disks.{$privateDiskName}.driver");
            $disksToTest[$privateDiskName] = $privateDiskDriver;
        }

        $this->info('=======================================================');
        $this->info('Storage Connection Test');
        $this->info('=======================================================');
        $this->newLine();

        $allTestsPassed = true;

        foreach ($disksToTest as $diskName => $diskDriver) {
            $this->info("Testing {$diskName} disk (driver: {$diskDriver})...");
            $this->newLine();

            $passed = $this->testDisk($diskName, $cleanup);
            $allTestsPassed = $allTestsPassed && $passed;

            $this->newLine();
        }

        $this->info('=======================================================');
        if ($allTestsPassed) {
            $this->info('✓ All storage tests passed!');
            return Command::SUCCESS;
        } else {
            $this->error('✗ Some storage tests failed. Please check your configuration.');
            return Command::FAILURE;
        }
    }

    /**
     * Test a specific disk
     *
     * @param string $diskName
     * @param bool $cleanup
     * @return bool
     */
    private function testDisk(string $diskName, bool $cleanup): bool
    {
        try {
            // Get the storage disk - use default disk if diskName is 'local'
            $disk = ($diskName === 'default' || $diskName === config('filesystems.default'))
                ? Storage::disk(config('filesystems.default'))
                : Storage::disk($diskName);

            // Display configuration
            $this->displayDiskConfig($diskName);
            $this->newLine();

            // Generate test data
            $testFileName = 'test-' . Str::random(10) . '.txt';
            $testFilePath = 'storage-test/' . $testFileName;
            $testContent = 'This is a test file created at ' . now()->toDateTimeString() . "\n";
            $testContent .= 'Testing storage connectivity for: ' . config('app.name') . "\n";
            $testContent .= 'Environment: ' . config('app.env') . "\n";

            // Step 1: Upload test file
            $this->info('1. Uploading test file...');
            $uploaded = $disk->put($testFilePath, $testContent);

            if (!$uploaded) {
                $this->error('   ✗ Failed to upload test file');
                return false;
            }
            $this->info('   ✓ Test file uploaded successfully');

            // Step 2: Verify file exists
            $this->info('2. Verifying file exists...');
            if (!$disk->exists($testFilePath)) {
                $this->error('   ✗ Test file does not exist after upload');
                return false;
            }
            $this->info('   ✓ Test file exists');

            // Step 3: Read file content
            $this->info('3. Reading file content...');
            $retrievedContent = $disk->get($testFilePath);

            if ($retrievedContent !== $testContent) {
                $this->error('   ✗ Retrieved content does not match uploaded content');
                $this->warn('   Expected: ' . substr($testContent, 0, 50) . '...');
                $this->warn('   Got: ' . substr($retrievedContent, 0, 50) . '...');
                return false;
            }
            $this->info('   ✓ File content matches');

            // Step 4: Get file metadata
            $this->info('4. Checking file metadata...');
            $size = $disk->size($testFilePath);
            $lastModified = $disk->lastModified($testFilePath);
            $this->info("   ✓ File size: {$size} bytes");
            $this->info("   ✓ Last modified: " . date('Y-m-d H:i:s', $lastModified));

            // Step 5: Get URL (if applicable)
            try {
                $url = $disk->url($testFilePath);
                $this->info("   ✓ File URL: {$url}");
            } catch (\Exception $e) {
                $this->warn('   ! URL generation not supported or failed: ' . $e->getMessage());
            }

            // Step 6: Cleanup
            if ($cleanup) {
                $this->info('5. Cleaning up test file...');
                $deleted = $disk->delete($testFilePath);

                if (!$deleted) {
                    $this->warn('   ! Failed to delete test file');
                    $this->warn("   ! Please manually delete: {$testFilePath}");
                } else {
                    $this->info('   ✓ Test file deleted successfully');
                }
            } else {
                $this->warn("5. Test file left in storage: {$testFilePath}");
                $this->warn('   Use --cleanup flag to automatically delete test files');
            }

            $this->info("\n✓ {$diskName} disk test PASSED");
            return true;

        } catch (\Exception $e) {
            $this->error("\n✗ {$diskName} disk test FAILED");
            $this->error('Error: ' . $e->getMessage());
            $this->newLine();
            $this->warn('Stack trace:');
            $this->warn($e->getTraceAsString());
            return false;
        }
    }

    /**
     * Display disk configuration information
     *
     * @param string $diskName
     * @return void
     */
    private function displayDiskConfig(string $diskName): void
    {
        $diskConfig = config("filesystems.disks.{$diskName}");

        $this->info('Configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Driver', $diskConfig['driver'] ?? 'N/A'],
                ['Region', $diskConfig['region'] ?? 'N/A'],
                ['Bucket', $diskConfig['bucket'] ?? 'N/A'],
                ['Endpoint', $diskConfig['endpoint'] ?? 'N/A (AWS S3)'],
                ['Root Path', $diskConfig['root'] ?? '/' ],
                ['Visibility', $diskConfig['visibility'] ?? 'N/A'],
            ]
        );
    }
}
