#!/usr/bin/perl
require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %in);

&ReadParse();
&ui_print_header(undef, $text{'adopt_title'}, "");

my $cerr = &cli_available();
&error($cerr) if $cerr;

my ($ex, $out);
if ($in{'all'}) {
	($ex, $out) = run_cli('adopt', '--all');
} else {
	my $dom = &validate_domain_name($in{'dom'});
	&require_domain_access($dom);
	($ex, $out) = run_cli('adopt', $dom);
	&print_cli_result($ex, $out, &text('adopt_done', $dom));
	print &ui_links_row([
		&ui_link('domain.cgi?dom='.&urlize($dom), $text{'index_manage'}),
		&ui_link('discover.cgi', $text{'index_discover'}),
	]);
	&ui_print_footer('discover.cgi', $text{'index_discover'});
	exit;
}

&print_cli_result($ex, $out, $text{'ok_done'});
print &ui_links_row([
	&ui_link('discover.cgi', $text{'index_discover'}),
	&ui_link('index.cgi', $text{'index_return'}),
]);
&ui_print_footer('discover.cgi', $text{'index_discover'});
