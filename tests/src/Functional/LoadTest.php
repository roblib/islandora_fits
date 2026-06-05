<?php

namespace Drupal\Tests\islandora_fits\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Basic smoke test for islandora_fits.
 *
 * @group islandora_fits
 */
class LoadTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'islandora',
    'islandora_fits',
  ];

  /**
   * The default theme for the test site.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the front page loads successfully.
   */
  public function testFrontPage(): void {
    $this->drupalGet('');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('The website encountered an unexpected error');
  }

  /**
   * Tests that the user page is reachable.
   */
  public function testUserPage(): void {
    $this->drupalGet('/user');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('The website encountered an unexpected error');
  }
  /**
   * Tests that the media create page is reachable.
   */
  public function testAddMediaPage(): void {
    $account = $this->drupalCreateUser([], NULL, TRUE);
    $this->drupalLogin($account);
    $this->drupalGet('/media/add/fits_technical_metadata');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('The website encountered an unexpected error');
  }


}
