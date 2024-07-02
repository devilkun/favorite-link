<?php

declare(strict_types=1);

/**
 * Copyright (c) 2018-2024 guanguans<ityaozm@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/guanguans/favorite-link
 */

namespace App\Commands;

use GrahamCampbell\GitHub\Facades\GitHub;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laminas\Feed\Writer\Feed;

/**
 * @see https://docs.laminas.dev/laminas-feed/
 * @see https://github.com/composer/packagist/blob/main/src/Controller/FeedController.php
 */
final class FeedCommand extends Command
{
    private const string REPOSITORY_LINK = 'https://github.com/guanguans/favorite-link';
    private const string FLAG = '### ';
    protected $signature = <<<'SIGNATURE'
        feed:generate
        {--from=README.md : The path of the README file.}
        SIGNATURE;
    protected $description = 'Generate feed.';

    public function handle(): void
    {
        /** @noinspection NullPointerExceptionInspection */
        str(File::get($this->option('from')))
            ->after(self::FLAG)
            ->prepend(self::FLAG)
            ->explode(\PHP_EOL)
            ->filter(filled(...))
            ->reduce(
                static function (Collection $carry, string $line) use (&$date): Collection {
                    $line = str($line);

                    if ($line->startsWith(self::FLAG)) {
                        $date = $line->remove(self::FLAG)->trim();

                        return $carry;
                    }

                    return $carry->add([
                        'date' => ($date = Date::createFromTimestamp(strtotime((string) $date)))->isCurrentDay() ? now() : $date,
                        'title' => $title = (string) $line->match('/\[.*\]/')->trim('[]'),
                        'link' => $link = (string) $line->match('/\(.*\)/')->trim('()'),
                        'repository_link' => $repositoryLink = self::REPOSITORY_LINK,
                        'content' => <<<HTML
                            <a href="$link" target="_blank">$title</a>
                            <h3>相关链接</h3>
                            <ul>
                                <li><a href="$link" target="_blank">$link</a></li>
                                <li><a href="$repositoryLink" target="_blank">$repositoryLink</a></li>
                            </ul>
                            HTML,
                    ]);
                },
                collect()
            )
            ->tap(function (Collection $items): void {
                $feed = $this->createDefaultFeed();

                $items->each(static function (array $item) use ($feed): void {
                    $entry = $feed->createEntry();

                    $entry->setEncoding('UTF-8');
                    $entry->setTitle($item['title']);
                    $entry->setLink($item['link']);
                    $entry->setContent($item['content']);
                    $entry->setDateCreated($item['date']);
                    $entry->setDateModified($item['date']);

                    $feed->addEntry($entry);
                });

                $feed->setDateModified($feed->getEntry()->getDateModified());

                foreach (['atom', 'rss'] as $type) {
                    $name = "README.$type";
                    $feed->setFeedLink("https://raw.githubusercontent.com/guanguans/favorite-link/master/$name", $type);
                    File::put(base_path($name), $feed->export($type));
                }
            })
            ->tap(fn () => Process::run(
                'git diff --color README.*',
                function (string $type, string $line): void {
                    $this->output->write($line);
                }
            ))
            ->tap(fn () => $this->output->success('Feed is done!'));
    }

    /**
     * @noinspection MethodVisibilityInspection
     */
    #[\Override]
    protected function rules(): array
    {
        return [
            'from' => 'required|ends_with:.md,.MD',
        ];
    }

    #[\Override]
    protected function messages(): array
    {
        return [
            'from.required' => 'The path of the README file is required.',
            'from.ends_with' => 'The path of the README file must end with .md or .MD.',
        ];
    }

    private function createDefaultFeed(): Feed
    {
        $feed = new Feed;
        $feed->setEncoding('UTF-8');
        $feed->setTitle($title = '❤️ 每天收集喜欢的开源项目');
        $feed->setDescription($title);
        $feed->setLink(self::REPOSITORY_LINK);
        $feed->addAuthor([
            'name' => 'guanguans',
            'email' => 'ityaozm@gmail.com',
            'uri' => 'https://github.com/guanguans',
        ]);

        return $feed;
    }
}
