<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Recipes\Render\AutoLinker;
use PHPUnit\Framework\TestCase;

final class AutoLinkerTest extends TestCase
{
    private AutoLinker $linker;

    /** @var array<string,string> */
    private array $index;

    protected function setUp(): void
    {
        $this->linker = new AutoLinker;
        $this->index = [
            'cornbread'    => 'Cornbread',
            'apple-pie'    => 'Apple Pie',          // 9 chars
            'pie-crust'    => 'Pie Crust',          // 9 chars
            'sourdough'    => 'Sourdough Starter',  // 17 chars
            'mom-s-pie'    => "Mom's Apple Pie",    // 15 chars, apostrophe
            'pasta'        => 'Pasta',              // 5 chars (sub-threshold)
            'bread'        => 'Bread',              // 5 chars (sub-threshold)
            'french-bread' => 'French Bread',       // 12 chars
        ];
    }

    public function test_bare_title_in_prose_gets_linked(): void
    {
        $out = $this->linker->link('Serve with cornbread on the side.', $this->index, 'apple-pie');
        $this->assertStringContainsString('[Cornbread](/recipes/cornbread)', $out);
    }

    public function test_title_inside_bracket_ref_is_not_double_linked(): void
    {
        // [[brackets]] are normally resolved before AutoLinker runs, but
        // defense in depth: if they sneak through, leave them alone.
        $out = $this->linker->link('Pair with [[cornbread]] for crunch.', $this->index, 'apple-pie');
        $this->assertSame('Pair with [[cornbread]] for crunch.', $out);
    }

    public function test_title_inside_markdown_link_is_not_double_linked(): void
    {
        $in = 'See [Cornbread](/recipes/cornbread) for details.';
        $out = $this->linker->link($in, $this->index, 'apple-pie');
        $this->assertSame($in, $out);
    }

    public function test_title_inside_code_span_is_not_double_linked(): void
    {
        $in = 'The string `Cornbread` is a recipe slug.';
        $out = $this->linker->link($in, $this->index, 'apple-pie');
        $this->assertSame($in, $out);
    }

    public function test_title_inside_html_tag_attribute_is_not_double_linked(): void
    {
        $in = '<a title="Cornbread">link</a>';
        $out = $this->linker->link($in, $this->index, 'apple-pie');
        $this->assertSame($in, $out);
    }

    public function test_self_reference_not_linked(): void
    {
        // Note: the linker treats `currentSlug` as off-limits even if the
        // title appears bare in the prose. Recipes don't link to themselves.
        $out = $this->linker->link('This cornbread is dense.', $this->index, 'cornbread');
        $this->assertSame('This cornbread is dense.', $out);
    }

    public function test_repeated_title_only_first_occurrence_linked(): void
    {
        $out = $this->linker->link('Try cornbread; cornbread keeps well; cornbread is nice.', $this->index, 'apple-pie');
        // Exactly one link, and the second/third occurrences stay bare.
        $linkCount = substr_count($out, '[Cornbread](/recipes/cornbread)');
        $this->assertSame(1, $linkCount, 'Expected exactly one link substitution');
        $this->assertStringContainsString('cornbread keeps well', $out);
        $this->assertStringContainsString('cornbread is nice', $out);
    }

    public function test_sub_six_char_title_skipped_even_when_bare(): void
    {
        // "Pasta" (5 chars) is sub-threshold; "Bread" (5 chars) too.
        $out = $this->linker->link('Make some pasta with that bread.', $this->index, 'apple-pie');
        $this->assertSame('Make some pasta with that bread.', $out);
    }

    public function test_case_insensitive_match_uses_canonical_case_in_link(): void
    {
        $out = $this->linker->link('Try CORNBREAD or cornbread or Cornbread.', $this->index, 'apple-pie');
        // Only the first occurrence gets linked, and the link text uses the
        // index's canonical case ("Cornbread"), not the prose's case.
        $this->assertStringContainsString('[Cornbread](/recipes/cornbread)', $out);
        $this->assertStringNotContainsString('[CORNBREAD]', $out);
    }

    public function test_word_boundary_no_partial_match(): void
    {
        $out = $this->linker->link('cornbread-related thoughts and cornbreads everywhere.', $this->index, 'apple-pie');
        // "cornbread-related" and "cornbreads" should NOT match the title
        // "Cornbread" because the word boundary requires \b on both sides.
        $this->assertSame('cornbread-related thoughts and cornbreads everywhere.', $out);
    }

    public function test_apostrophe_in_title_does_not_break_regex(): void
    {
        $out = $this->linker->link("Like Mom's Apple Pie but better.", $this->index, 'apple-pie');
        $this->assertStringContainsString("[Mom's Apple Pie](/recipes/mom-s-pie)", $out);
    }

    public function test_longer_title_wins_over_shorter_prefix(): void
    {
        // "Apple Pie" (9 chars) and "Pie Crust" (9 chars) and "Pie" (3, sub-threshold)
        // exist in the index. "Apple Pie" should match in "Apple Pie" prose, not
        // a phantom "Pie" alone. Add "French Bread" + "Bread" — same situation.
        $out = $this->linker->link('Use French Bread for this.', $this->index, 'apple-pie');
        $this->assertStringContainsString('[French Bread](/recipes/french-bread)', $out);
        // The bare "Bread" sub-threshold title shouldn't have created a stray link.
        $this->assertStringNotContainsString('[Bread]', $out);
    }

    public function test_empty_index_returns_unchanged(): void
    {
        $out = $this->linker->link('Some cornbread mentioned.', [], 'apple-pie');
        $this->assertSame('Some cornbread mentioned.', $out);
    }

    public function test_no_match_in_prose_returns_unchanged(): void
    {
        $out = $this->linker->link('No recipes named here.', $this->index, 'apple-pie');
        $this->assertSame('No recipes named here.', $out);
    }
}
