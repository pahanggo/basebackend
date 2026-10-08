<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class LanguageSwitcherTest extends TestCase
{
    private function admin(): User
    {
        return User::where('email', 'administrator@example.com')->firstOrFail();
    }

    public function test_switcher_renders_every_available_locale_in_the_header()
    {
        $html = $this->actingAs($this->admin())->get('/app/dashboard')->getContent();

        foreach (config('app.available_locales') as $key => $label) {
            $this->assertStringContainsString(route('locale.switch', $key), $html, "no link for $key");
            $this->assertStringContainsString($label, $html, "no label for $key");
        }
    }

    public function test_switching_persists_the_locale_and_redirects_back()
    {
        $res = $this->actingAs($this->admin())
            ->from('/app/dashboard')
            ->get(route('locale.switch', 'ta'));

        $res->assertRedirect('/app/dashboard');
        $res->assertSessionHas('locale', 'ta');
    }

    public function test_selected_locale_applies_to_the_next_request()
    {
        $html = $this->actingAs($this->admin())
            ->withSession(['locale' => 'zh_CN'])
            ->get('/app/dashboard')
            ->getContent();

        $this->assertStringContainsString('lang="zh-CN"', $html, 'html lang attribute not switched to a valid BCP 47 tag');
        $this->assertStringContainsString(trans('backpack::base.logout', [], 'zh_CN'), $html, 'Chinese chrome not rendered');
    }

    public function test_unknown_locale_is_rejected_and_never_written_to_the_session()
    {
        $res = $this->actingAs($this->admin())->get('/app/locale/de');

        $res->assertNotFound();
        $res->assertSessionMissing('locale');
    }

    public function test_locale_dropped_from_config_stops_applying()
    {
        config(['app.available_locales' => ['ms_MY' => 'Bahasa Melayu', 'en' => 'English']]);

        $html = $this->actingAs($this->admin())
            ->withSession(['locale' => 'zh_CN'])
            ->get('/app/dashboard')
            ->getContent();

        $this->assertStringContainsString('lang="ms-MY"', $html, 'stale session locale still applied');
    }

    public function test_switching_without_a_referer_falls_back_to_the_dashboard()
    {
        $res = $this->actingAs($this->admin())->get(route('locale.switch', 'ta'));

        $res->assertRedirect(backpack_url('dashboard'));
    }

    public function test_guest_can_switch_language_on_the_login_page()
    {
        $res = $this->from('/app/login')->get(route('locale.switch', 'ta'));

        $res->assertRedirect('/app/login');
        $res->assertSessionHas('locale', 'ta');
    }
}
