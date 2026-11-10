<?php

namespace App\Domain;

/**
 * Server-side port of the app's RecommendationEngine
 * (lib/features/onboarding/domain/recommendation_engine.dart). Keep the rules in sync with it.
 *
 * Transparent, rule-based mapping from questionnaire answers to program phases.
 * It is NOT a clinical screening tool and must not be presented as one.
 * Answers: single/mood -> string, multi -> list of strings (option `v` values).
 */
final class RecommendationEngine
{
    public const PHASE_ORDER = ['p1', 'p2', 'p3', 'p4'];

    /**
     * @return array{reasonsByPhase: array<string, list<string>>, showSupport: bool, suggestProfessional: bool,
     *               dailyMinutes: ?string, learningStyle: ?string, preferredKind: ?string}
     */
    public function recommend(array $answers): array
    {
        $single = fn (string $id) => is_string($answers[$id] ?? null) ? $answers[$id] : null;
        $multi = fn (string $id) => is_array($answers[$id] ?? null) ? array_values(array_filter($answers[$id], 'is_string')) : [];

        $goals = $multi('goals');
        $body = $multi('body');
        $support = $multi('support');
        $mood = $single('mood');
        $stress = $single('stress');
        $sleep = $single('sleep');
        $express = $single('express');

        $reasons = [];
        $add = function (string $phaseId, string $why) use (&$reasons) {
            $reasons[$phaseId][] = $why;
        };

        if (in_array('understand', $goals, true)) {
            $add('p1', 'You want to understand your mental health better');
        }
        if (in_array('habits', $goals, true)) {
            $add('p1', 'You want to build healthier habits');
        }
        if (in_array('stress', $goals, true)) {
            $add('p2', 'You want help managing stress');
        }
        if ($stress === 'high' || $stress === 'overwhelming') {
            $add('p2', 'Your stress has been high lately');
        }
        if ($body !== [] && ! in_array('none', $body, true)) {
            $add('p2', "You've noticed stress showing up in your body");
        }
        if ($sleep === 'patchy' || $sleep === 'poor') {
            $add('p2', 'Your sleep has been unsettled');
        }
        if ($express === 'hard' || $express === 'veryhard') {
            $add('p3', 'Talking about feelings can be hard for you');
        }
        if (in_array('emotions', $goals, true) || in_array('talk', $goals, true)) {
            $add('p3', 'You want to get better at noticing and sharing feelings');
        }
        if (in_array('support', $goals, true)) {
            $add('p4', 'You want to support someone you care about');
        }
        if ($reasons === []) {
            $add('p1', 'A great place for anyone to start');
        }

        $ordered = [];
        foreach (self::PHASE_ORDER as $id) {
            if (isset($reasons[$id])) {
                $ordered[$id] = $reasons[$id];
            }
        }

        $lowMood = $mood === 'low' || $mood === 'verylow';
        $showSupport = $mood === 'verylow' || $stress === 'overwhelming' || (in_array('noone', $support, true) && $lowMood);

        return [
            'reasonsByPhase' => $ordered,
            'showSupport' => $showSupport,
            'suggestProfessional' => $showSupport || $mood === 'low' || $sleep === 'poor',
            'dailyMinutes' => $single('time'),
            'learningStyle' => $single('style'),
            'preferredKind' => match ($single('style')) {
                'read' => 'learn',
                'watch' => 'watch',
                'do' => 'activity',
                default => null,
            },
        ];
    }

    /**
     * Therapist focus areas suggested by the answers (keys of config('mindease.focus_areas')).
     * Used to order the therapist list when the user hasn't picked a focus on the booking screen.
     *
     * @return list<string>
     */
    public function focusAreas(array $answers): array
    {
        $single = fn (string $id) => is_string($answers[$id] ?? null) ? $answers[$id] : null;
        $goals = is_array($answers['goals'] ?? null) ? $answers['goals'] : [];
        $body = is_array($answers['body'] ?? null) ? $answers['body'] : [];

        $areas = [];
        if (in_array('stress', $goals, true) || in_array($single('stress'), ['high', 'overwhelming'], true)
            || ($body !== [] && ! in_array('none', $body, true))) {
            $areas[] = 'stress';
        }
        if (in_array($single('mood'), ['low', 'verylow'], true)) {
            $areas[] = 'low_mood';
        }
        if (in_array($single('sleep'), ['patchy', 'poor'], true)) {
            $areas[] = 'sleep';
        }
        if (in_array('emotions', $goals, true) || in_array('talk', $goals, true)
            || in_array($single('express'), ['hard', 'veryhard'], true)) {
            $areas[] = 'emotions';
        }
        if (in_array('support', $goals, true)) {
            $areas[] = 'supporting_others';
        }
        if (in_array('habits', $goals, true)) {
            $areas[] = 'wellbeing';
        }

        return $areas;
    }
}
