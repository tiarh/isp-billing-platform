<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiFlashService
{
    public function __construct(protected KerjaanKnowledgeBase $knowledgeBase) {}

    /**
     * Parse pesan masuk dari grup KERJAAN menggunakan Gemini Flash (Fallback AI Layer 2).
     *
     * @return array<string, mixed>|null
     */
    public function parseKerjaanMessage(string $text, array $context): ?array
    {
        $apiKey = (string) config('services.gemini.api_key', '');
        if ($apiKey === '') {
            $apiKey = (string) Setting::get('ticket_progress_gemini_api_key', '');
        }
        if ($apiKey === '') {
            return null;
        }

        $model = (string) config('services.gemini.model', 'gemini-3.5-flash');
        $workDate = (string) ($context['work_date'] ?? now('Asia/Jakarta')->toDateString());

        $systemPrompt = $this->buildSystemPrompt($workDate);
        $history = $this->knowledgeBase->recentChatHistory($workDate);

        $contents = array_merge($history, [
            [
                'role' => 'user',
                'parts' => [['text' => $text]],
            ],
        ]);

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                    'systemInstruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'contents' => $contents,
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'kind' => [
                                    'type' => 'STRING',
                                    'enum' => ['task', 'team_roster', 'task_cancel', 'batch', 'progress', 'control', 'question', 'chat'],
                                ],
                                'action' => ['type' => 'STRING'],
                                'work_date' => ['type' => 'STRING'],
                                'team_no' => ['type' => 'INTEGER'],
                                'technicians' => [
                                    'type' => 'ARRAY',
                                    'items' => ['type' => 'STRING'],
                                ],
                                'site' => ['type' => 'STRING'],
                                'task_type' => ['type' => 'STRING'],
                                'task' => ['type' => 'STRING'],
                                'reply_text' => ['type' => 'STRING'],
                                'items' => [
                                    'type' => 'ARRAY',
                                    'items' => [
                                        'type' => 'OBJECT',
                                        'properties' => [
                                            'kind' => ['type' => 'STRING'],
                                            'action' => ['type' => 'STRING'],
                                            'work_date' => ['type' => 'STRING'],
                                            'team_no' => ['type' => 'INTEGER'],
                                            'technicians' => [
                                                'type' => 'ARRAY',
                                                'items' => ['type' => 'STRING'],
                                            ],
                                            'site' => ['type' => 'STRING'],
                                            'task_type' => ['type' => 'STRING'],
                                            'task' => ['type' => 'STRING'],
                                        ],
                                    ],
                                ],
                            ],
                            'required' => ['kind', 'reply_text'],
                        ],
                        'temperature' => 0.1,
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('[GeminiFlash] API call failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 200),
                ]);

                return null;
            }

            $rawJson = $response->json('candidates.0.content.parts.0.text');
            if (! is_string($rawJson) || trim($rawJson) === '') {
                return null;
            }

            $parsed = json_decode($rawJson, true);

            return is_array($parsed) ? $parsed : null;
        } catch (\Throwable $e) {
            Log::warning('[GeminiFlash] Exception during API call', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function buildSystemPrompt(string $workDate): string
    {
        $behaviorRules = <<<'RULE'
Kamu adalah Asisten AI Pintar Operasional Skynet Malang di grup WhatsApp KERJAAN.
Tugas utamamu: Memahami pesan anggota tim/teknisi/Tiar dan mengonversinya menjadi JSON terstruktur untuk sistem scheduler & progress teknisi.

ATURAN PERILAKU & SAFETY:
1. Kamu PAHAM SELURUH ARSITEKTUR eBilling Skynet (termasuk topologi 102/106, MikroTik, OLT, PSB WA, dsb).
2. Jika input berisi LEBIH DARI 1 PERINTAH SEKALIGUS (misal: "edit team X ... DAN tambah team Y ..."), KAMU WAJIB MENGEMBALIKAN "kind": "batch" dengan array "items".
3. Jika input hanya 1 perintah tunggal, kembalikan JSON dengan kind: "task" / "team_roster" / "task_cancel" / "progress" / "control" / "question" / "chat".
4. Jika waktu input sudah lewat jam 17:00 WIB, default work_date adalah BESOK (kecuali disebutkan "hari ini").
5. JANGAN PERNAH menyebarkan data sensitif billing/pelanggan ke grup lain.
6. Jawablah secara ramah, profesional, dan to the point seperti tim Antigravity.

FORMAT OUTPUT SINGLE COMMAND (JSON):
{
  "kind": "task|team_roster|task_cancel|progress|control|question|chat",
  "action": "create_manual_task|set_team_roster|cancel_manual_task|record_progress|ask_clarification|reply_chat",
  "work_date": "YYYY-MM-DD",
  "team_no": 4,
  "technicians": ["RAFLY", "FATKAH"],
  "site": "KLAMPOK",
  "task_type": "pembangunan|psb|maintenance|standby",
  "task": "DEPLOY ODP TARGET 8 TITIK",
  "progress": "DEPLOY",
  "status": "draft|proses|close",
  "priority": "normal|high|urgent",
  "reply_text": "Pesan balasan singkat dan ramah yang akan dikirim bot ke grup KERJAAN"
}

FORMAT OUTPUT BATCH / MULTI-COMMAND (JSON):
{
  "kind": "batch",
  "action": "batch_input",
  "work_date": "YYYY-MM-DD",
  "items": [
    {
      "kind": "team_roster",
      "action": "set_team_roster",
      "work_date": "YYYY-MM-DD",
      "team_no": 7,
      "technicians": ["BAIM", "IPIN"]
    },
    {
      "kind": "task",
      "action": "create_manual_task",
      "work_date": "YYYY-MM-DD",
      "team_no": 8,
      "technicians": ["JAZULI", "FATKAH"],
      "site": "LAWANG",
      "task_type": "maintenance",
      "task": "DISMANTLE LAWANG",
      "status": "draft"
    }
  ],
  "reply_text": "Siap! Team 7 di-set hanya Baim & Ipin, dan Team 8 (Jazuli & Fatkah) dibentuk untuk task Dismantle Lawang."
}
RULE;

        $staticKnowledge = $this->knowledgeBase->staticKnowledge();
        $dynamicContext = $this->knowledgeBase->dynamicContext($workDate);

        return $behaviorRules."\n\n"
            .$dynamicContext."\n\n"
            .$staticKnowledge;
    }
}
