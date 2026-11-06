<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MigrationController extends Controller
{
    public function index()
    {
        return response()->json(['data' => $this->status()]);
    }

    public function run()
    {
        $before = $this->appliedNames();

        try {
            $code = Artisan::call('migrate', ['--force' => true]);
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'message' => 'Something went wrong, try again later or contact system admin',
            ], 500);
        }

        $output = trim(Artisan::output());
        $status = $this->status($before);
        $status['output'] = $code === 0 ? $output : '';

        if ($code !== 0) {
            report(new \RuntimeException($output !== '' ? $output : 'Migration failed'));

            return response()->json([
                'message' => 'Something went wrong, try again later or contact system admin',
            ], 500);
        }

        return response()->json(['data' => $status]);
    }

    /**
     * @param  list<string>  $before
     * @return array{applied: list<array{name: string, skipped: bool}>, pending: list<string>, ran: list<string>, output: string}
     */
    private function status(array $before = []): array
    {
        $applied = $this->appliedNames();
        $files = $this->files();
        $beforeLookup = array_fill_keys($before, true);

        return [
            'applied' => array_map(fn (string $name) => [
                'name' => $name,
                'skipped' => $before === [] || isset($beforeLookup[$name]),
            ], $applied),
            'pending' => array_values(array_filter($files, fn (string $name) => ! in_array($name, $applied, true))),
            'ran' => array_values(array_filter($applied, fn (string $name) => $before !== [] && ! isset($beforeLookup[$name]))),
            'output' => '',
        ];
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $paths = glob(database_path('migrations/*.php')) ?: [];
        $names = array_map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME), $paths);
        sort($names);

        return array_values($names);
    }

    /**
     * @return list<string>
     */
    private function appliedNames(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        return DB::table('migrations')->orderBy('id')->pluck('migration')->map(fn ($name) => (string) $name)->all();
    }
};
