<?php
/*
 * Copyright 2015 Google Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Cloud\Samples\Bookshelf;

use Silex\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Class ControllersTest.
 */
class controllersTest extends WebTestCase
{
    use SkipTestsIfMissingCredentialsTrait;
    /**
     * Creates the application.
     *
     * @return \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../../src/app.php';
        require __DIR__.'/../../src/controllers.php';
        $app['bookshelf.model'] = new CloudsqlModel();
        // Set a tiny page size so it's easy to test paging.
        $app['bookshelf.page_size'] = 1;
        $this->storage = $app['bookshelf.storage'] = new FakeImageStorage();

        return $app;
    }

    public function testRoot()
    {
        $client = $this->createClient();
        $client->request('GET', '/');

        $this->assertTrue($client->getResponse()->isRedirect());
    }

    public function testPaging()
    {
        $client = $this->createClient();
        $client->followRedirects();
        $crawler = $client->request('GET', '/books/');

        $editLink = $crawler
            ->filter('a:contains("Add")') // find all links with the text "Add"
            ->link();

        $crawler = $client->click($editLink);

        // Fill the form and submit it, twice.
        $submitButton = $crawler->selectButton('submit');
        $form = $submitButton->form();

        $photo = new UploadedFile(
            __DIR__.'/../lib/CatHat.jpg',
            'CatHat.jpg',
            'image/jpg',
            filesize(__DIR__.'/../lib/CatHat.jpg')
        );
        $crawler = $client->submit($form, array(
            'title' => 'The Cat in the Hat',
            'author' => 'Dr. Suess',
            'publishedDate' => '1957-01-01',
            'image' => $photo,
        ));
        $this->assertEquals(
            'img1',
            $crawler->filter('.book-image')->attr('src')
        );

        // Capture the delete button.
        $deleteCatHat = $crawler->selectButton('submit');

        $crawler = $client->submit($form, array(
            'title' => 'Treasure Island',
            'author' => 'Robert Louis Stevenson',
            'publishedDate' => '1883-01-01',
        ));
        $deleteTreasureIsland = $crawler->selectButton('submit');

        try {
            // Now go through the pages one by one and confirm we saw the books
            // we just added.
            $foundTreasureIsland = false;
            $foundCatHat = false;
            $crawler = $client->request('GET', '/');
            while (true) {
                $foundCatHat = $foundCatHat ||
                    $crawler->filter('h4:contains("The Cat in the Hat")');
                $foundTreasureIsland = $foundTreasureIsland ||
                    $crawler->filter('h4:contains("Treasure Island")');
                $more = $crawler->filter('a:contains("More")');
                if (count($more)) {
                    $crawler = $client->click($more->link());
                } else {
                    break;
                }
            }
            $this->assertTrue($foundTreasureIsland);
            $this->assertTrue($foundCatHat);
        } finally {
            $client->submit($deleteCatHat->form());
            $client->submit($deleteTreasureIsland->form());
        }
    }

    public function testCrud()
    {
        $client = $this->createClient();
        $client->followRedirects();
        $crawler = $client->request('GET', '/books/');

        $editLink = $crawler
            ->filter('a:contains("Add")') // find all links with the text "Add"
            ->link();

        // and click it
        $crawler = $client->click($editLink);

        // Fill the form and submit it.
        $submitButton = $crawler->selectButton('submit');
        $form = $submitButton->form();

        $photo = new UploadedFile(
            __DIR__.'/../lib/CatHat.jpg',
            'CatHat.jpg',
            'image/jpg',
            filesize(__DIR__.'/../lib/CatHat.jpg')
        );
        $crawler = $client->submit($form, array(
            'title' => 'Where the Red Fern Grows',
            'author' => 'Will Rawls',
            'publishedDate' => '1961',
            'image' => $photo,
        ));

        // Make sure the page contents match what we just submitted.
        $title = $crawler->filter('.book-title')->text();
        $this->assertContains('Where the Red Fern Grows', $title);
        $author = $crawler->filter('.book-author')->text();
        $this->assertContains('Will Rawls', $author);
        $viewBookUrl = $client->getRequest()->getUri();

        // Click the edit button.
        $editLink = $crawler->filter('a:contains("Edit")')->link();
        $crawler = $client->click($editLink);

        // Fill the form and submit it.
        $submitButton = $crawler->selectButton('submit');
        $form = $submitButton->form();
        $crawler = $client->submit($form, array(
            'title' => 'Where the Red Fern Grows',
            'author' => 'Wilson Rawls',
            'publishedDate' => '1961',
            'image' => $photo,
        ));

        // Make sure the page contents match what we just submitted.
        $title = $crawler->filter('.book-title')->text();
        $this->assertContains('Where the Red Fern Grows', $title);
        $author = $crawler->filter('.book-author')->text();
        $this->assertContains('Wilson Rawls', $author);

        // Click the delete button.
        $deleteButton = $crawler->selectButton('submit');
        $client->submit($deleteButton->form());
        $this->assertTrue($client->getResponse()->isOk());

        // Confirm that we don't find the book anymore.
        $client->request('GET', $viewBookUrl);
        $this->assertEquals(404, $client->getResponse()->getStatusCode());

        // Confirm that we can't delete again it either.
        $client->submit($deleteButton->form());
        $this->assertEquals(404, $client->getResponse()->getStatusCode());

        // And confirm that we can't edit again.
        $client->click($editLink);
        $this->assertEquals(404, $client->getResponse()->getStatusCode());
        $client->submit($submitButton->form());
        $this->assertEquals(404, $client->getResponse()->getStatusCode());
    }
}
