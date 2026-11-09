<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Services\SchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    public function test_two_simultaneous_postgresql_transactions_confirm_only_once(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Exige PostgreSQL e pcntl.');
        }
        $this->assertSame('testing', DB::connection()->getDatabaseName(), 'Execute apenas no banco testing.');
        $space = Space::factory()->create();
        $user = User::factory()->create();
        $start = CarbonImmutable::tomorrow(config('placer.timezone'))->setTime(14, 0)->format('Y-m-d\TH:i');
        $files = [tempnam(sys_get_temp_dir(), 'placer-race-'), tempnam(sys_get_temp_dir(), 'placer-race-')];
        $children = [];
        $sockets = [];
        DB::disconnect();
        try {
            foreach ($files as $file) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Falha ao criar processo de teste.');
                }
                if ($pid === 0) {
                    fclose($pair[0]);
                    fread($pair[1], 1);
                    fclose($pair[1]);
                    DB::purge();
                    try {
                        DB::transaction(function () use ($user, $space, $start) {
                            app(SchedulingService::class)->reserve($user, $space->id, $start);
                            usleep(300000);
                        });
                        file_put_contents($file, 'confirmed');
                    } catch (ValidationException) {
                        file_put_contents($file, 'conflict');
                    } catch (\Throwable $exception) {
                        file_put_contents($file, 'error:'.$exception->getMessage());
                    }
                    DB::disconnect();
                    exit(0);
                }
                fclose($pair[1]);
                $children[] = $pid;
                $sockets[] = $pair[0];
            }
            foreach ($sockets as $socket) {
                fwrite($socket, '1');
                fclose($socket);
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            $results = array_map('file_get_contents', $files);
            sort($results);
            $this->assertSame(['confirmed', 'conflict'], $results);
            $this->assertSame(1, Reservation::where('space_id', $space->id)->where('status', 'confirmed')->count());
        } finally {
            DB::purge();
            Reservation::where('space_id', $space->id)->delete();
            Space::whereKey($space->id)->delete();
            Category::whereKey($space->category_id)->delete();
            User::whereKey($user->id)->delete();
            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
