<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    public function test_guest_sees_the_landing_page_instead_of_the_spa(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Google ビジネスプロフィールを、', false)
            ->assertSee('href="/login"', false)
            ->assertSee('href="'.route('privacy').'"', false)
            ->assertDontSee('<div id="app"></div>', false);
    }

    public function test_guest_sees_the_privacy_policy(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('プライバシーポリシー')
            ->assertSee('Google Business Profile API')
            ->assertSee('info@stoc-plus.site')
            ->assertDontSee('<div id="app"></div>', false);
    }

    public function test_login_is_still_served_by_the_spa(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }
}
