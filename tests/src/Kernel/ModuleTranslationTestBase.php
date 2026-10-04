<?php

declare(strict_types=1);

namespace Drupal\Tests\interval_trigger\Kernel;

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\Gettext;

abstract class ModuleTranslationTestBase extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'file',
    'language',
    'locale',
    'help',
    'interval_trigger',
  ];

  abstract protected function moduleName(): string;

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'language', 'locale']);
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location', 'locale_file']);

    $ru = ConfigurableLanguage::createFromLangcode('ru');
    $ru->save();
    Gettext::fileToDatabase((object) ['uri' => $this->poPath(), 'langcode' => 'ru'], []);

    $this->config('system.site')->set('default_langcode', 'ru')->save();
    $this->container->get('language.default')->set($ru);
    $this->container->get('language_manager')->reset();
    $this->container->get('string_translation')->setDefaultLangcode('ru');
  }

  protected function modulePath(): string {
    return $this->container->get('extension.list.module')->getPath($this->moduleName());
  }

  protected function poPath(): string {
    return $this->modulePath() . '/translations/' . $this->moduleName() . '.ru.po';
  }

  protected function poItems(): array {
    $reader = new PoStreamReader();
    $reader->setLangcode('ru');
    $reader->setURI($this->poPath());
    $reader->open();
    $items = [];
    while ($item = $reader->readItem()) {
      $source = $item->getSource();
      $items[] = [
        'source' => is_array($source) ? $source[0] : $source,
        'context' => $item->getContext(),
        'plural' => $item->isPlural(),
      ];
    }
    return $items;
  }

  protected function sourceFiles(): array {
    $files = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->modulePath(), \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
      $path = str_replace('\\', '/', $file->getPathname());
      if (preg_match('#/(tests|translations|\.git)/#', $path)) {
        continue;
      }
      if (preg_match('/\.(php|module|inc|install|yml|twig)$/', $path)) {
        $files[] = $path;
      }
    }
    return $files;
  }

  protected function sourceStrings(): array {
    $strings = [];
    foreach ($this->sourceFiles() as $file) {
      $code = file_get_contents($file);
      if (str_ends_with($file, '.yml')) {
        $data = (array) Yaml::decode($code);
        array_walk_recursive($data, function ($value) use (&$strings) {
          if (is_string($value)) {
            $strings[$value] = TRUE;
          }
        });
      }
      elseif (str_ends_with($file, '.twig')) {
        if (preg_match("/^label: '(.*)'$/m", $code, $m)) {
          $strings[$m[1]] = TRUE;
        }
        preg_match_all('/\{% trans %\}(.*?)\{% endtrans %\}/s', $code, $m);
        foreach ($m[1] as $text) {
          $strings[preg_replace('/\{\{ (\w+) \}\}/', '@$1', $text)] = TRUE;
        }
      }
      else {
        foreach (token_get_all($code) as $token) {
          if (!is_array($token)) {
            continue;
          }
          if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $strings[stripslashes(substr($token[1], 1, -1))] = TRUE;
          }
          if ($token[0] === T_DOC_COMMENT && preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $token[1], $m)) {
            foreach ($m[1] as $value) {
              $strings[$value] = TRUE;
            }
          }
        }
      }
    }
    return $strings;
  }

  public function testPoMsgidsAreUsedInSource(): void {
    $strings = $this->sourceStrings();
    $items = $this->poItems();
    $this->assertNotEmpty($items);
    foreach ($items as $item) {
      $this->assertArrayHasKey(
        $item['source'],
        $strings,
        sprintf('msgid from the .po file not found in the source (code changed, .po not?): "%s"', $item['source'])
      );
    }
  }

  public function testNoCyrillicStringLiteralsInSource(): void {
    $cyrillic = '/\p{Cyrillic}/u';
    foreach ($this->sourceFiles() as $file) {
      $code = file_get_contents($file);
      if (str_ends_with($file, '.yml')) {
        foreach (explode("\n", $code) as $n => $line) {
          if (!str_starts_with(ltrim($line), '#')) {
            $this->assertDoesNotMatchRegularExpression($cyrillic, $line, "$file:" . ($n + 1));
          }
        }
        continue;
      }
      if (str_ends_with($file, '.twig')) {
        $this->assertDoesNotMatchRegularExpression($cyrillic, $code, $file);
        continue;
      }
      foreach (token_get_all($code) as $token) {
        if (!is_array($token)) {
          continue;
        }
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING || $token[0] === T_ENCAPSED_AND_WHITESPACE) {
          $this->assertDoesNotMatchRegularExpression($cyrillic, $token[1], "$file:{$token[2]}");
        }
        if ($token[0] === T_DOC_COMMENT && preg_match_all('/@(?:Plural)?Translation\((.*?)\)/s', $token[1], $m)) {
          foreach ($m[1] as $annotation) {
            $this->assertDoesNotMatchRegularExpression($cyrillic, $annotation, "$file:{$token[2]}");
          }
        }
      }
    }
  }

  public function testHelpTopicsRenderInRussian(): void {
    $dir = $this->modulePath() . '/help_topics';
    $topics = glob($dir . '/*.html.twig');
    $this->assertNotEmpty($topics, 'The module must ship help topics.');

    $po_sources = array_column($this->poItems(), 'source');
    $manager = $this->container->get('plugin.manager.help_topic');
    $renderer = $this->container->get('renderer');
    foreach ($topics as $file) {
      $id = basename($file, '.html.twig');
      $topic = $manager->createInstance($id);
      $this->assertMatchesRegularExpression('/\p{Cyrillic}/u', (string) $topic->getLabel(), "$id: заголовок не переведён.");

      $html = $renderer->executeInRenderContext(new RenderContext(), function () use ($topic, $renderer) {
        $body = $topic->getBody();
        return (string) $renderer->render($body);
      });
      preg_match_all('/\{% trans %\}(.*?)\{% endtrans %\}/s', file_get_contents($file), $m);
      foreach ($m[1] as $msgid) {
        if (!in_array(preg_replace('/\{\{ (\w+) \}\}/', '@$1', $msgid), $po_sources, TRUE)) {
          continue;
        }
        $head = preg_split('/\{\{|</', $msgid)[0];
        if (strlen($head) >= 15) {
          $this->assertStringNotContainsString($head, $html, "$id: блок не переведён — msgid в Twig и .po разошлись?");
        }
      }
    }
  }

  protected function ru(string $string, array $options = []): string {
    return (string) new TranslatableMarkup($string, [], $options + ['langcode' => 'ru']);
  }

}
