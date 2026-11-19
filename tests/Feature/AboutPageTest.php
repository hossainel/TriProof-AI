<?php

namespace Tests\Feature;

use Tests\TestCase;

class AboutPageTest extends TestCase
{
    public function test_about_page_is_available_to_guests_in_english(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('About TriProof AI')
            ->assertSee('Our Mission')
            ->assertSee('Lead Architect')
            ->assertSee(route('login'), false);
    }

    public function test_about_page_renders_in_bangla_from_the_session_locale(): void
    {
        $this->withSession(['locale' => 'bn'])
            ->get(route('about'))
            ->assertOk()
            ->assertSee('TriProof AI সম্পর্কে')
            ->assertSee('আমাদের লক্ষ্য')
            ->assertSee('সাইন ইন')
            ->assertSee('বাংলা');
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
