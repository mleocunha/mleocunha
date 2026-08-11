#!/usr/bin/perl
# Ensure linked pages keep Virtualmin left menu context when opened from feature_links.
use strict;
use warnings;

sub cgi_args {
	my ($cgi) = @_;
	my $dom = $main::in{'dom'};
	if ($dom) {
		return "dom=".&urlize($dom);
	}
	return "";
}

1;
