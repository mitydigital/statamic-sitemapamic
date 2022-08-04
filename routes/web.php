<?php

// add the standard sitemap.xml
Route::get('sitemap.xml', 'SitemapamicController@show');
Route::get('sitemap_{submap}.xml', 'SitemapamicController@show');
