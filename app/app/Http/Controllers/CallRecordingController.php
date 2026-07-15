<?php

namespace App\Http\Controllers;

use App\Models\AiCallLog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves call recordings to the /call-logs UI. Node writes raw
 * 48 kHz mono Int16 LE PCM to `public/uploads/call-recordings/{call}_{side}.pcm`
 * during the call (so the disk write is hot-path-cheap). On first
 * playback we lazily wrap a WAV header + cache the result, so the
 * operator's <audio> tag gets a playable file in one round-trip.
 *
 * Workspace-scoped: a row's caller is enforced via the parent
 * AiCallLog's workspace_id — operators in other workspaces 404.
 */
class CallRecordingController extends Controller
{
    private const SAMPLE_RATE = 48000;
    private const CHANNELS    = 1;
    private const BITS        = 16;

    /**
     * GET /call-logs/{id}/audio/{side}
     *  side: agent | user | mixed (mixed = both interleaved; falls back to agent if user missing)
     */
    public function audio(int $id, string $side)
    {
        $wsId = (int) (Auth::user()?->current_workspace_id ?? 0);
        $log = AiCallLog::where('workspace_id', $wsId)->findOrFail($id);

        if (!in_array($side, ['agent', 'user', 'mixed'], true)) {
            abort(404);
        }

        // Resolve the underlying call's meta_call_id — that's what
        // Node uses to name the .pcm files.
        $metaCallId = (string) ($log->twilio_call_sid ?: '');
        if ($metaCallId === '') {
            abort(404, 'no recording reference');
        }

        $dir = public_path('uploads/call-recordings');
        $userPcm  = $dir . DIRECTORY_SEPARATOR . $metaCallId . '_user.pcm';
        $agentPcm = $dir . DIRECTORY_SEPARATOR . $metaCallId . '_agent.pcm';

        if ($side === 'user' && !is_file($userPcm))   abort(404, 'user recording missing');
        if ($side === 'agent' && !is_file($agentPcm)) abort(404, 'agent recording missing');

        // For mixed we need both sides; fall back to whichever exists.
        if ($side === 'mixed' && !is_file($userPcm) && !is_file($agentPcm)) {
            abort(404, 'no recording on disk');
        }

        $wavPath = $dir . DIRECTORY_SEPARATOR . $metaCallId . '_' . $side . '.wav';
        if (!is_file($wavPath)) {
            try {
                if ($side === 'mixed') {
                    $this->writeMixedWav($userPcm, $agentPcm, $wavPath);
                } else {
                    $pcmPath = $side === 'user' ? $userPcm : $agentPcm;
                    $this->writeWav($pcmPath, $wavPath);
                }
            } catch (\Throwable $e) {
                Log::warning('[REC] wav build failed: ' . $e->getMessage());
                abort(500, 'recording build failed');
            }
        }

        return response()->file($wavPath, [
            'Content-Type'        => 'audio/wav',
            'Content-Disposition' => 'inline; filename="' . $metaCallId . '_' . $side . '.wav"',
            'Cache-Control'       => 'private, max-age=86400',
        ]);
    }

    /** Wrap a raw PCM file in a WAV (RIFF) header and dump to disk. */
    private function writeWav(string $pcmPath, string $wavPath): void
    {
        $pcm = file_get_contents($pcmPath);
        if ($pcm === false) {
            throw new \RuntimeException('read pcm failed');
        }
        $wav = $this->wavHeader(strlen($pcm)) . $pcm;
        if (file_put_contents($wavPath, $wav, LOCK_EX) === false) {
            throw new \RuntimeException('write wav failed');
        }
    }

    /**
     * Sample-by-sample mix of user + agent PCM. Both sides are
     * 16-bit signed mono @ 48 kHz, so the average of each pair is
     * the mixed sample. Pads the shorter file with silence so the
     * timeline lines up with `started_at + duration_seconds`.
     */
    private function writeMixedWav(string $userPcm, string $agentPcm, string $wavPath): void
    {
        $u = is_file($userPcm)  ? file_get_contents($userPcm)  : '';
        $a = is_file($agentPcm) ? file_get_contents($agentPcm) : '';
        $len = max(strlen($u), strlen($a));
        if ($len === 0) throw new \RuntimeException('no pcm to mix');

        // Pad with zeros to equal length so we mix sample-aligned.
        $u = str_pad($u, $len, "\x00");
        $a = str_pad($a, $len, "\x00");

        // 16-bit signed little-endian samples.
        $mixed = '';
        for ($i = 0; $i + 2 <= $len; $i += 2) {
            $uu = unpack('s', substr($u, $i, 2))[1];
            $aa = unpack('s', substr($a, $i, 2))[1];
            $mix = (int) max(-32768, min(32767, $uu + $aa));
            $mixed .= pack('s', $mix);
        }

        $wav = $this->wavHeader(strlen($mixed)) . $mixed;
        if (file_put_contents($wavPath, $wav, LOCK_EX) === false) {
            throw new \RuntimeException('write mixed wav failed');
        }
    }

    /** Minimal 44-byte WAV header for our PCM format. */
    private function wavHeader(int $dataLen): string
    {
        $byteRate   = self::SAMPLE_RATE * self::CHANNELS * (self::BITS / 8);
        $blockAlign = self::CHANNELS * (self::BITS / 8);
        return 'RIFF'
            . pack('V', 36 + $dataLen)
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)
            . pack('v', 1)                // PCM
            . pack('v', self::CHANNELS)
            . pack('V', self::SAMPLE_RATE)
            . pack('V', $byteRate)
            . pack('v', $blockAlign)
            . pack('v', self::BITS)
            . 'data'
            . pack('V', $dataLen);
    }
}
