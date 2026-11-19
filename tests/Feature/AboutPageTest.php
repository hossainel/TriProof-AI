<?php

namespace Tests\Feature;

use Tests\TestCase;

class AboutPageTest extends TestCase
{
    private const TEAM = [
        'Feroz Mahmud',
        'Shafayet Al Hossain',
        'Remon Islam',
        'Masrurul Hasan Maktum',
        'Golam Rabbani',
    ];

    public function test_about_page_is_available_to_guests_in_english(): void
    {
        $response = $this->get(route('about'))
            ->assertOk()
            ->assertSee('About TriProof AI')
            ->assertSee('Our Mission')
            ->assertSee('How It Works')
            ->assertSee('Tech Stack')
            ->assertSee('Meet the Team')
            ->assertSee('Built Together')
            ->assertSee('Log in to the verifier')
            ->assertSee(route('login'), false)
            ->assertSee(asset('images/feroz_mahmud.jpg'), false)
            ->assertSee(asset('images/shafayet.jpg'), false)
            ->assertSee(asset('images/remon_islam.png'), false)
            ->assertSee(asset('images/masrurul_hasan.jpg'), false)
            ->assertSee(asset('images/golam_rabbani.jpg'), false);

        foreach (self::TEAM as $name) {
            $response->assertSee($name);
        }
    }

    public function test_about_page_renders_in_bangla_from_the_session_locale(): void
    {
        $response = $this->withSession(['locale' => 'bn'])
            ->get(route('about'))
            ->assertOk()
            ->assertSee('TriProof AI সম্পর্কে')
            ->assertSee('আমাদের লক্ষ্য')
            ->assertSee('কিভাবে কাজ করে')
            ->assertSee('আমাদের টিম')
            ->assertSee('একসাথে গড়া')
            ->assertSee('ভেরিফায়ারে লগ ইন করুন')
            ->assertSee('বাংলা');

        foreach (self::TEAM as $name) {
            $response->assertSee($name);
        }
    }

    public function test_login_page_links_to_the_about_page(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('about'), false)
            ->assertSee('About TriProof AI');
    }

    public function test_locale_switch_route_stores_the_locale_and_redirects_back(): void
    {
        $this->from(route('about'))
            ->get(route('locale.switch', ['locale' => 'bn']))
            ->assertRedirect(route('about'))
            ->assertSessionHas('locale', 'bn');
    }

    public function test_public_legal_pages_render(): void
    {
        $this->get(route('privacy'))->assertOk()->assertSee('Privacy');
        $this->get(route('terms'))->assertOk()->assertSee('Terms');
    }
}
