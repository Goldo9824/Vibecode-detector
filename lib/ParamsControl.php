<?php
declare(strict_types=1);

require_once __DIR__ . '/Crawler.php';
require_once __DIR__ . '/RepoAnalyzer.php';

/**
 * The reading-settings control that sits inside the field, left of the run
 * button.
 *
 * It exists as a class because the same control now appears in three of the
 * five panels with different sliders in it — pages for a live page, files for
 * a repository, both for auto, which does not know yet which of the two it is
 * looking at. Three hand-copied blocks of markup is three places for the ids
 * to drift out of step with assets/js/app.js, which addresses every part of
 * this by id.
 *
 * The sliders themselves are declared here rather than in the page because
 * their bounds are not presentation: they are the read budgets, and they come
 * from the classes that enforce them.
 */
final class ParamsControl
{
    /**
     * One slider's definition.
     *
     * @return array{key:string,label:string,min:int,max:int,value:int,note:string,applies:string}
     */
    public static function pages(): array
    {
        return array(
            'key'     => 'pages',
            'label'   => 'Pages to read',
            'min'     => 1,
            'max'     => Crawler::MAX_PAGES,
            'value'   => 1,
            'note'    => 'One page, read deeply — its stylesheets, its scripts and its source maps.',
            'applies' => 'an address',
        );
    }

    /** @return array{key:string,label:string,min:int,max:int,value:int,note:string,applies:string} */
    public static function files(): array
    {
        return array(
            'key'     => 'files',
            'label'   => 'Files to read',
            'min'     => 1,
            'max'     => RepoAnalyzer::MAX_CODE_FILES_CAP,
            'value'   => RepoAnalyzer::MAX_CODE_FILES,
            'note'    => 'Three source files, the largest worth reading. Enough for a style, not for a codebase.',
            'applies' => 'a repository',
        );
    }

    /**
     * The whole control for one panel.
     *
     * $sliders is a list of the definitions above. When there is more than one
     * the panel says which input each applies to, because in auto mode only
     * one of them will end up mattering and which one is not decided until
     * the paste is read.
     *
     * @param array<int,array<string,mixed>> $sliders
     */
    public static function render(string $mode, array $sliders): string
    {
        $m = h($mode);
        $multi = count($sliders) > 1;

        $out  = '<div class="params" data-params="' . $m . '">';
        $out .= '<button type="button" class="params-open" id="params-open-' . $m . '"'
              . ' aria-expanded="false" aria-controls="params-panel-' . $m . '"'
              . ' title="What this reading is allowed to open">'
              . self::icon()
              . '<span class="visually-hidden">Reading settings</span>'
              . '<span class="params-badge" id="params-badge-' . $m . '" hidden></span>'
              . '</button>';

        $out .= '<div class="params-panel" id="params-panel-' . $m . '" hidden>';
        foreach ($sliders as $i => $s) {
            $key = h((string) $s['key']);
            $id  = $key . '-' . $m;
            $out .= '<div class="params-slider"' . ($i > 0 ? ' data-second="1"' : '') . '>';
            $out .= '<label class="params-label" for="' . $id . '">'
                  . '<span>' . h((string) $s['label']) . '</span>'
                  . '<output class="params-value" id="' . $key . '-out-' . $m . '" for="' . $id . '">'
                  . h((string) $s['value']) . '</output>'
                  . '</label>';
            $out .= '<input class="params-range" type="range" id="' . $id . '" name="' . $key . '"'
                  . ' min="' . h((string) $s['min']) . '" max="' . h((string) $s['max']) . '"'
                  . ' step="1" value="' . h((string) $s['value']) . '">';
            $out .= '<div class="params-scale"><span>' . h((string) $s['min']) . '</span>'
                  . '<span>' . h((string) $s['max']) . '</span></div>';
            $out .= '<p class="params-note" id="' . $key . '-note-' . $m . '">' . h((string) $s['note']) . '</p>';
            if ($multi) {
                $out .= '<p class="params-applies">Used when what you paste is ' . h((string) $s['applies']) . '.</p>';
            }
            $out .= '</div>';
        }
        $out .= '</div></div>';

        return $out;
    }

    /**
     * Two sliders on two rails. Drawn rather than shipped as a font or an
     * image so it takes the accent from the same token everything else does.
     */
    private static function icon(): string
    {
        return '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false">'
             . '<path d="M2 4.5h5M11 4.5h3M2 11.5h3M9 11.5h5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'
             . '<circle cx="9" cy="4.5" r="2" fill="none" stroke="currentColor" stroke-width="1.4"/>'
             . '<circle cx="7" cy="11.5" r="2" fill="none" stroke="currentColor" stroke-width="1.4"/>'
             . '</svg>';
    }
}
