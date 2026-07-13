import { afterEach, describe, expect, it, vi } from 'vitest';
import { runShutdown, type WorkerLike } from '../src/shutdown';

/**
 * Tests runShutdown() directly (fake deps/timers), NOT registerShutdown() +
 * a real `process.emit('SIGTERM')` — see shutdown.ts's own doc comment on
 * why the two are split.
 */

function fakeHealthServer(): { close: ReturnType<typeof vi.fn> } {
  return {
    close: vi.fn((cb?: () => void) => {
      cb?.();
    }),
  };
}

afterEach(() => {
  vi.useRealTimers();
});

describe('runShutdown', () => {
  it('awaits a pending in-flight job (worker.close()) before exiting 0', async () => {
    let resolveClose!: () => void;
    const worker: WorkerLike = {
      close: () =>
        new Promise<void>((resolve) => {
          resolveClose = resolve;
        }),
    };
    const healthServer = fakeHealthServer();
    const exit = vi.fn();

    const shutdownPromise = runShutdown({ worker, healthServer: healthServer as never, shutdownTimeoutMs: 10_000, exit });

    // Give the pending-job simulation a moment, then resolve it — exit()
    // must not have been called yet (still "awaiting the in-flight job").
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(exit).not.toHaveBeenCalled();

    resolveClose();
    await shutdownPromise;

    expect(healthServer.close).toHaveBeenCalledTimes(1);
    expect(exit).toHaveBeenCalledTimes(1);
    expect(exit).toHaveBeenCalledWith(0);
  });

  it('hard-kills (exit 1) after WORKER_SHUTDOWN_TIMEOUT_MS when worker.close() never resolves', async () => {
    vi.useFakeTimers();
    const worker: WorkerLike = {
      close: () => new Promise<void>(() => {}), // never resolves — simulates a genuinely stuck job
    };
    const healthServer = fakeHealthServer();
    const exit = vi.fn();

    // Deliberately not awaited — this promise never settles in this test.
    void runShutdown({ worker, healthServer: healthServer as never, shutdownTimeoutMs: 5_000, exit });

    await vi.advanceTimersByTimeAsync(4_999);
    expect(exit).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(2);
    expect(exit).toHaveBeenCalledTimes(1);
    expect(exit).toHaveBeenCalledWith(1);

    // healthServer.close() is never reached — worker.close() itself never
    // resolved, so runShutdown() never got past the `await worker.close()`.
    expect(healthServer.close).not.toHaveBeenCalled();
  });

  it('exits 1 when worker.close() rejects', async () => {
    const worker: WorkerLike = { close: async () => { throw new Error('close failed'); } };
    const healthServer = fakeHealthServer();
    const exit = vi.fn();

    await runShutdown({ worker, healthServer: healthServer as never, shutdownTimeoutMs: 10_000, exit });

    expect(exit).toHaveBeenCalledTimes(1);
    expect(exit).toHaveBeenCalledWith(1);
  });

  it('only calls exit once even if the hard-kill timer and a slow-but-successful close race', async () => {
    vi.useFakeTimers();
    let resolveClose!: () => void;
    const worker: WorkerLike = {
      close: () =>
        new Promise<void>((resolve) => {
          resolveClose = resolve;
        }),
    };
    const healthServer = fakeHealthServer();
    const exit = vi.fn();

    const shutdownPromise = runShutdown({ worker, healthServer: healthServer as never, shutdownTimeoutMs: 1_000, exit });

    await vi.advanceTimersByTimeAsync(1_001); // hard-kill fires -> exit(1)
    expect(exit).toHaveBeenCalledTimes(1);
    expect(exit).toHaveBeenCalledWith(1);

    resolveClose(); // close() resolves AFTER the hard-kill already fired
    await shutdownPromise;

    expect(exit).toHaveBeenCalledTimes(1); // still just once — no exit(0) after the fact
  });
});
