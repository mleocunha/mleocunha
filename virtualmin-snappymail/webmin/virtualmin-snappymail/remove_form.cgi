#!/usr/bin/perl
require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %in);

&ReadParse();
my $dom = &validate_domain_name($in{'dom'});
&require_domain_access($dom);

&ui_print_header(undef, $text{'remove_title'}, "");

print &ui_form_start('remove.cgi', 'post');
print &ui_hidden('dom', $dom);
print &ui_table_start(&text('remove_header', $dom), undef, 2);
print &ui_table_row($text{'remove_app'},
	&ui_radio('mode', 'app', [
		[ 'app', $text{'remove_app'} ],
		[ 'sub', &text('remove_sub', $dom) ],
	]));
print &ui_table_end();
print "<p><b>", &html_escape($text{'remove_confirm'}), "</b></p>\n";
print &ui_form_end([ [ 'ok', $text{'remove_submit'} ] ]);

&ui_print_footer('domain.cgi?dom='.&urlize($dom), $dom);
