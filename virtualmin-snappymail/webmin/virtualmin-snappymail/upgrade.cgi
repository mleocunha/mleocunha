#!/usr/bin/perl
require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %in);

&ReadParse();
my $dom = &validate_domain_name($in{'dom'});
&require_domain_access($dom);

&ui_print_header(undef, $text{'upgrade_title'}, "");

if (!$in{'confirm'}) {
	print &ui_confirmation_form(
		'upgrade.cgi',
		&text('upgrade_confirm', $dom),
		[ [ 'dom', $dom ], [ 'confirm', 1 ] ],
		[ [ 'ok', $text{'upgrade_submit'} ] ],
		undef,
		undef,
	);
	&ui_print_footer('domain.cgi?dom='.&urlize($dom), $dom);
	exit;
}

my ($ex, $out) = run_cli('upgrade', $dom);
&print_cli_result($ex, $out, $text{'ok_done'});
print &ui_links_row([ &ui_link('domain.cgi?dom='.&urlize($dom), $text{'index_manage'}) ]);
&ui_print_footer('domain.cgi?dom='.&urlize($dom), $dom);
