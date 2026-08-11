#!/usr/bin/perl
require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text);

&ui_print_header(undef, $text{'audit_title'}, "");

my $cerr = &cli_available();
&error($cerr) if $cerr;

my ($ex, $out) = run_cli('audit');
&print_cli_result($ex, $out, $text{'audit_run'});

print &ui_links_row([ &ui_link('index.cgi', $text{'index_return'}) ]);
&ui_print_footer('index.cgi', $text{'index_return'});
