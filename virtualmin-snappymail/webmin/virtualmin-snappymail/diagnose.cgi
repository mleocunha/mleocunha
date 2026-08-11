#!/usr/bin/perl
require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %in);

&ReadParse();
my $dom = &validate_domain_name($in{'dom'});
&require_domain_access($dom);

&ui_print_header(undef, $text{'diagnose_title'}, "");
my ($ex, $out) = run_cli('diagnose', $dom);
&print_cli_result($ex, $out, $text{'ok_done'});
print &ui_links_row([ &ui_link('domain.cgi?dom='.&urlize($dom), $text{'index_manage'}) ]);
&ui_print_footer('domain.cgi?dom='.&urlize($dom), $dom);
