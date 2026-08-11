#!/usr/bin/perl
require 'virtualmin-snappymail-lib.pl';
use strict;
use warnings;
our (%text, %in);

&ReadParse();
my $dom = &validate_domain_name($in{'dom'});
&require_domain_access($dom);

&ui_print_header(undef, $text{'remove_title'}, "");

my @args = ('remove', $dom, '--yes');
push(@args, '--remove-subserver') if ($in{'mode'} // '') eq 'sub';

my ($ex, $out) = run_cli(@args);
&print_cli_result($ex, $out, $text{'ok_done'});
print &ui_links_row([ &ui_link('index.cgi', $text{'index_return'}) ]);
&ui_print_footer('index.cgi', $text{'index_return'});
