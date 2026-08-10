#!/usr/bin/perl
# Global status page for SnappyMail across Virtual Servers.

require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;

&ui_print_header(undef, $text{'index_title'} || 'SnappyMail', '');

print &ui_subheading($text{'index_global'} || 'All domains');

my ($ex, $out) = list_status_json();
if ($ex) {
    print &text('index_cli_error', &html_escape($out)), "<p>\n";
} else {
    print "<pre>", &html_escape($out), "</pre>\n";
}

print &ui_links_row([
    &ui_link('install_form.cgi', $text{'index_install'} || 'Install…'),
    &ui_link('discover.cgi', $text{'index_discover'} || 'Discover'),
]);

&ui_print_footer('/', $text{'index'});
