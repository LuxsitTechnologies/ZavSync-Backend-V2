<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiEvaluationCase;
use App\Models\AiEvaluationRun;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

class AiEvaluationService
{
    public function __construct(private readonly CopilotService $copilot) {}

    public function run(Request $request, User $user, string $companyId, AiEvaluationCase $case): AiEvaluationRun
    {
        $case = AiEvaluationCase::query()->where('company_id', $companyId)->where('is_active', true)->findOrFail($case->id);
        $run = AiEvaluationRun::query()->create(['company_id' => $companyId, 'ai_evaluation_case_id' => $case->id, 'run_by' => $user->id, 'status' => 'PROCESSING', 'started_at' => now()]);

        try {
            $conversation = AiConversation::query()->create(['company_id' => $companyId, 'user_id' => $user->id, 'title' => 'Evaluation: '.$case->name]);
            $answer = $this->copilot->respond($request, $user, $companyId, $conversation, $case->prompt, 'evaluation:'.$run->id);
            $checks = $this->checks($answer, $case);
            $total = count($checks);
            $passed = collect($checks)->where('passed', true)->count();
            $score = $total === 0 ? 10_000 : intdiv($passed * 10_000, $total);
            $run->update(['status' => 'COMPLETED', 'answer' => $answer->content, 'score_bps' => $score, 'checks' => $checks, 'completed_at' => now()]);

            return $run->fresh();
        } catch (Throwable $exception) {
            $run->update(['status' => 'FAILED', 'checks' => [['name' => 'execution', 'passed' => false, 'detail' => $exception->getMessage()]], 'completed_at' => now()]);
            throw $exception;
        }
    }

    /** @return array<int, array{name:string,passed:bool,detail:string}> */
    private function checks(AiMessage $message, AiEvaluationCase $case): array
    {
        $checks = [];
        $citationKeys = $message->citations()->with('source')->get()->flatMap(fn ($citation) => [$citation->knowledge_source_id, $citation->source->title])->all();
        foreach ($case->expected_citations ?? [] as $expected) {
            $checks[] = ['name' => 'citation:'.$expected, 'passed' => in_array($expected, $citationKeys, true), 'detail' => 'Expected source citation is present.'];
        }
        $toolNames = $message->toolRuns()->where('status', 'COMPLETED')->pluck('tool_name')->all();
        foreach ($case->expected_tools ?? [] as $expected) {
            $checks[] = ['name' => 'tool:'.$expected, 'passed' => in_array($expected, $toolNames, true), 'detail' => 'Expected read-only tool completed.'];
        }
        $actionTypes = $message->actionProposals()->pluck('action_type')->all();
        foreach ($case->forbidden_actions ?? [] as $forbidden) {
            $checks[] = ['name' => 'forbidden_action:'.$forbidden, 'passed' => ! in_array($forbidden, $actionTypes, true), 'detail' => 'Forbidden action was not proposed.'];
        }
        $checks[] = ['name' => 'answer_completed', 'passed' => $message->status === 'COMPLETED', 'detail' => 'Copilot returned a completed answer.'];

        return $checks;
    }
}
