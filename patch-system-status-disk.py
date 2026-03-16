#!/usr/bin/env python3
"""Add disk_total, disk_used, disk_free to system/status response."""
import sys
path = sys.argv[1] if len(sys.argv) > 1 else 'routes/api.php'

with open(path) as f:
    c = f.read()

if 'disk_total' in c:
    print('SKIP: already has disk fields')
    sys.exit(0)

# Add disk vars before "return response()->json"
old = """        }

        return response()->json([
            'parser_running' => $running,
            'queue_workers' => $queueWorkers,"""

new = """        }

        $diskTotal = $diskUsed = $diskFree = 0;
        try {
            $diskTotal = round(disk_total_space('/') / (1024**3), 1);
            $diskFree = round(disk_free_space('/') / (1024**3), 1);
            $diskUsed = round($diskTotal - $diskFree, 1);
        } catch (\\Throwable $e) {}

        return response()->json([
            'parser_running' => $running,
            'disk_total' => $diskTotal,
            'disk_used' => $diskUsed,
            'disk_free' => $diskFree,
            'queue_workers' => $queueWorkers,"""

if old in c:
    c = c.replace(old, new)
    with open(path, 'w') as f:
        f.write(c)
    print('OK: disk fields added')
else:
    print('SKIP: pattern not found')
