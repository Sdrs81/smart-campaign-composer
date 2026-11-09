<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\Variables;
use App\Segment\FieldDefinition;
use App\Segment\FieldRegistry;
use App\Segment\Operator;
use Illuminate\Http\JsonResponse;

/** The one place the frontend learns the limits, variables and rule labels, so it keeps no copy of them. */
class ContentRulesController extends Controller
{
    public function __invoke(FieldRegistry $registry): JsonResponse
    {
        return response()->json(['data' => [
            'channels' => config('delivery.channels'),
            'limits' => config('delivery.limits'),
            'brief' => config('llm.brief'),
            'variables' => array_map(
                fn (string $name): array => ['name' => $name, 'fallback' => Variables::fallback($name)],
                Variables::names(),
            ),
            'segment' => [
                'fields' => array_values(array_map(
                    fn (FieldDefinition $field): array => ['name' => $field->name, 'label' => $field->label],
                    $registry->all(),
                )),
                'operators' => array_map(
                    fn (Operator $operator): array => ['name' => $operator->value, 'template' => $operator->template()],
                    Operator::cases(),
                ),
            ],
        ]]);
    }
}
