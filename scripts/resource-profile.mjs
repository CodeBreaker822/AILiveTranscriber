import os from 'node:os';

export function resourceEnvironment() {
    const logicalProcessors = Math.max(1, os.availableParallelism?.() ?? os.cpus().length);
    const whisperThreads = logicalProcessors <= 2
        ? 1
        : Math.min(Math.max(1, Math.floor(logicalProcessors * 0.6)), logicalProcessors - 2);
    const totalMemoryMb = Math.floor(os.totalmem() / 1024 / 1024);
    const availableMemoryMb = Math.floor(os.freemem() / 1024 / 1024);
    const whisperMemoryBudgetMb = Math.max(1, Math.floor(totalMemoryMb / 2));
    const gpuAvailable = String(process.env.AI_TRANSCRIBER_GPU_AVAILABLE || 'false') === 'true';
    const gpuVramMb = gpuAvailable ? Math.max(0, Number(process.env.AI_TRANSCRIBER_GPU_VRAM_MB) || 0) : 0;
    const gpuVramBudgetMb = gpuVramMb > 0
        ? Math.max(0, gpuVramMb - Math.max(512, Math.floor(gpuVramMb / 4)))
        : 0;

    return {
        AI_TRANSCRIBER_WHISPER_THREADS: String(whisperThreads),
        AI_TRANSCRIBER_WHISPER_MEMORY_BUDGET_MB: String(whisperMemoryBudgetMb),
        AI_TRANSCRIBER_LOGICAL_PROCESSORS: String(logicalProcessors),
        AI_TRANSCRIBER_TOTAL_MEMORY_MB: String(totalMemoryMb),
        AI_TRANSCRIBER_AVAILABLE_MEMORY_MB: String(availableMemoryMb),
        AI_TRANSCRIBER_GPU_AVAILABLE: gpuAvailable && gpuVramMb >= 512 ? 'true' : 'false',
        AI_TRANSCRIBER_GPU_NAME: gpuAvailable ? String(process.env.AI_TRANSCRIBER_GPU_NAME || '') : '',
        AI_TRANSCRIBER_GPU_VRAM_MB: String(gpuVramMb),
        AI_TRANSCRIBER_WHISPER_GPU_VRAM_BUDGET_MB: String(gpuVramBudgetMb),
    };
}
