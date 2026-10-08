<?php

namespace Tests\Feature;

use App\Models\Auth\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportTranslationTest extends TestCase
{
    public static function localeProvider(): array
    {
        return [
            'ms_MY' => ['ms_MY'],
            'en' => ['en'],
            'ta' => ['ta'],
            'zh_CN' => ['zh_CN'],
        ];
    }

    private function admin(): User
    {
        return User::where('email', 'administrator@example.com')->firstOrFail();
    }

    private function visit(string $url, string $locale)
    {
        config(['app.locale' => $locale]);
        app()->setLocale($locale);

        return $this->actingAs($this->admin())->withSession(['locale' => $locale])->get($url);
    }

    #[DataProvider('localeProvider')]
    public function test_report_index_is_translated(string $locale)
    {
        $res = $this->visit('/app/report', $locale);
        $res->assertOk();

        foreach (['Reports', 'Access Control', 'R01: User Report', 'R02: Role Report'] as $key) {
            $res->assertSee(__($key, [], $locale), false);
        }
    }

    #[DataProvider('localeProvider')]
    public function test_user_report_is_translated(string $locale)
    {
        $res = $this->visit('/app/report/r01-user-report', $locale);
        $res->assertOk();

        foreach (['R01: User Report', 'Access Control', 'Created', 'Roles', 'Filter', 'Export', 'Name', 'Username', 'Email', 'Created At', 'Updated At'] as $key) {
            $res->assertSee(__($key, [], $locale), false);
        }
    }

    #[DataProvider('localeProvider')]
    public function test_role_report_is_translated(string $locale)
    {
        $res = $this->visit('/app/report/r02-role-report', $locale);
        $res->assertOk();

        foreach (['R02: Role Report', 'Access Control', 'Name', 'Users', 'Permissions', 'Created At', 'Updated At'] as $key) {
            $res->assertSee(__($key, [], $locale), false);
        }
    }

    #[DataProvider('localeProvider')]
    public function test_filter_subtitle_is_translated(string $locale)
    {
        $role = Role::firstOrFail();

        $res = $this->visit(
            '/app/report/r01-user-report?filter[created_at][from]=2020-01-01&filter[created_at][to]=2030-01-01&filter[role]='.$role->id,
            $locale
        );
        $res->assertOk();

        $expected = __('Where :conditions', [
            'conditions' => __('created between :from and :to', ['from' => '2020-01-01', 'to' => '2030-01-01'], $locale)
                .' '.__('and', [], $locale).' '
                .__('has role :role', ['role' => $role->name], $locale),
        ], $locale);

        $res->assertSee($expected, false);
    }

    public function test_report_route_slugs_stay_english_in_every_locale()
    {
        foreach (array_keys(config('app.available_locales')) as $locale) {
            app()->setLocale($locale);
            $this->assertSame('/app/report/r01-user-report', parse_url(route('reports.r01-user-report.index'), PHP_URL_PATH), "slug drifted in $locale");
        }
    }
}
