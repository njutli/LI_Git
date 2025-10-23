#!/bin/sh
# author: houtao1@huawei.com

: <<COMMENT
"%3d,%-3d %2s %3s " MAJOR(t->device), MINOR(t->device), act, rwbs);
"%llu + %u [%s]\n" t_sector(ent), t_sec(ent), cmd);
"[%s]\n", cmd
%16s-%-5d
COMMENT

function usage()
{
	cmd=$(basename $0)
	# <<- ignore the leading spaces
	cat <<-END >&2
	Usage: $cmd [-f dev] [-p pid] [-c cmd] [-m delay] [-a rw] [-s start] [-t type] [-r type,start,end]
		        [-C] [-n] [-d] [-q] [-g] file
	-f dev: filter by dev
	-p pid: filter by pid
	-c cmd: filter by cmd
	-r line|rtime|atime,start[,end]: filter by line range, relative time range, or absolute time range
	-m delay: minial delay in ms
	-a rw: R or W, default is RW
	-s start: Q (queued), I (inserted), or D (issued), default is D
	-t type: nop, blk, or blkparse
	-C: show per-cpu IO queue depth
	-n: no rw summary info
	-q: no io latency lines
	-d: debug on
	-V: output the events for bviewer
	-g: show the IO depth when IO completes
	Example:
	$cmd -q systrace.log
	$cmd -p 4543 -n -m 10 systrace.log
	$cmd -a '^FWS' systrace.log
END
	exit
}

declare -A magic_tbl
magic_tbl[nop]="block_rq_complete:\s\+[0-9]\+,[0-9]\+"
magic_tbl[blk]="\s[0-9]\+,[0-9]\+\s\+C\s\+[WR]"
magic_tbl[blkparse]="^\s*[0-9]\+,[0-9]\+\s\+[0-9]\+\s\+[0-9]\+"

dev_filter=""
pid_filter=""
cmd_filter=""
ftype=""
lower=0
rw_filter="[RW]"
start_act="D"
summary=1
dbg=0
outlier=1
dump_qdepth=1
per_cpu_qdepth=0
bviewer_output=0
error_summary=1
iodepth_when_c=0

function probe_ftype()
{
	local fname=$1
	local seq=( "nop" "blk" "blkparse" )

	for t in ${seq[@]}
	do
		magic=${magic_tbl[$t]}
		if grep -m 1 -q $magic $fname
		then
			ftype=$t
			break
		fi
	done
}

while getopts f:p:c:m:a:s:t:r:ndqgCVh opt
do
	case $opt in
	f) dev_filter=$OPTARG ;;
	p) pid_filter=$OPTARG ;;
	c) cmd_filter=$OPTARG ;;
	m) lower=$OPTARG ;;
	a) rw_filter=$OPTARG ;;
	s) start_act=$OPTARG ;;
	t) ftype=$OPTARG ;;
	r) echo "Not Supported option r"; exit 1 ;;
	n) summary=0 ;;
	d) dbg=1 ;;
	q) outlier=0 ;;
	g) iodepth_when_c=1 ;;
	C) per_cpu_qdepth=1 ;;
	V) bviewer_output=1 ;;
	h|?) usage ;;
	esac
done

if ! [[ "$start_act" =~ [QID] ]]
then
	echo -e "Invalid action $start_act\n"
	usage
fi

shift $(expr $OPTIND - 1)

if test $# -ne 1 || ! test -f $1
then
	usage
fi

if test -z "$ftype"
then
	probe_ftype $1

	if test -z "$ftype"
	then
		echo "Invalid file $1: unknown format"
		usage
	fi
elif ! [[ "$ftype" =~ nop|blk|blkparse ]]
then
	echo -e "Invalid file type $ftype\n"
	usage
fi

tmpfile=/tmp/$$.blktrace
trap "rm -f $tmpfile" 1 2 3 15 EXIT

if test $ftype == "blkparse"
then
	if awk '11 <= NF && $6 ~ "^[QIDC]$"' $1 | sort -k 4n,4n -o $tmpfile
	then
		dst=$tmpfile
	else
		echo "invalid blktrace file $1 (sort -k 4n,4n error)"
		exit 1
	fi
else
	dst=$1
fi

if test "${bviewer_output}" -eq 1
then
	summary=0
	outlier=0
	dump_qdepth=0
	per_cpu_qdepth=0
	error_summary=0
fi

awk -v dev_filter=$dev_filter -v pid_filter=$pid_filter -v cmd_filter=$cmd_filter \
    -v lower=$lower -v rw_filter=$rw_filter -v start_act=$start_act \
    -v dbg=$dbg -v summary=$summary -v outlier=$outlier -v ftype=$ftype \
	-v dump_qdepth=${dump_qdepth} -v per_cpu_qdepth=${per_cpu_qdepth} \
	-v iodepth_when_c=${iodepth_when_c} \
	-v bviewer_output=${bviewer_output} -v error_summary=${error_summary} '
BEGIN \
{
	READ = 1;
	WRITE = 2;
	RDWR = 3;

	rw_str[READ] = "Read";
	rw_str[WRITE] = "Write";
	rw_str[RDWR] = "Read|Write"

	metrics["latency"] = 0;
	metrics["size"] = 0;
	metrics["seek"] = 0;

	for (rw = READ; rw <= RDWR; rw++) {
		for (m in metrics) {
			max_i[rw][m] = 0;
			raw_cnt[rw][m] = 0;
		}
	}

	last_seek = "";

	ignored_line = 0;
	unmatched_line = 0;
	filtered_line = 0;

	m_unit_divisor["latency"] = 1;
	m_unit_divisor["size"] = 1024;
	m_unit_divisor["seek"] = 1024;

	m_unit["latency"] = "ms";
	m_unit["size"] = "KB";
	m_unit["seek"] = "KB";

	m_fmt["latency"] = "%.1f";
	m_fmt["size"] = "%u";
	m_fmt["seek"] = "%u";

	m_hist_w["latency"] = 8;
	m_hist_w["size"] = 6;
	m_hist_w["seek"] = 10;

	act2func_tbl["Q"] = "block_bio_queue:";
	act2func_tbl["I"] = "block_rq_insert:";
	act2func_tbl["D"] = "block_rq_issue:";
	act2func_tbl["C"] = "block_rq_complete:";

	func_pos_ofs_tbl["I"] = 1;
	func_pos_ofs_tbl["D"] = 1;
	func_pos_ofs_tbl["C"] = 0;

	for (a in act2func_tbl) {
		if (a != start_act && a != "C") {
			delete act2func_tbl[a];
		}
	}

	max_cpu = -1;

	# ts_b:ts_e:rwbs:dev:pos:size:cpu:pid:name
	bviewer_fmt = "%u:%u:%s:%s:%s:%s:%s:%s:%s\n"

	issuer_ptn = "(.*)-([0-9]+)( +[(] *([-0-9]+)[)])?$"

	hdr_displayed = 0;

	if (dbg) {
		printf("file format: %s\n", ftype)
	}
}

function find_cpu_pos(start, end)
{
	for (idx = start; idx <= end; idx++) {
		if ("[" == substr($idx, 1, 1) &&
			"]" == substr($idx, length($idx), 1)) {
			break;
		}
	}

	return idx;
}

function match_filter(dev, issuer, rwbs)
{
	if (dev_filter != "" && dev != dev_filter) {
		return 0;
	}

	if (cmd_filter != "" && issuer !~ "<idle>-0" &&
		issuer != "[0]-0" && issuer !~ cmd_filter) {
		return 0;
	}

	if (pid_filter != "" && issuer !~ "<idle>-0" && issuer != "[0]-0") {
		pid_ptn = sprintf("-%-5d\\>", pid_filter);
		tgid_ptn = sprintf("\\<(\\s*%d)\\>", pid_filter);
		if (issuer !~ pid_ptn && issuer !~ tgid_ptn) {
			return 0;
		}
	}

	if (rwbs !~ rw_filter) {
		return 0;
	}

	return 1;
}

/* from iolatency */
function star(sval, smax, swidth) {
	stars = "";
	if (smax == 0) {
		return "";
	}

	for (si = 0; si < (swidth * sval / smax); si++) {
		stars = stars "#";
	}

	return stars;
}

/* from iolatency */
function show_hist(hist, cnt, unit, min_width)
{
	total_v = 0;
	max_v = 0;
	for (i = 0; i <= cnt; i++) {
		freq = hist[i];
		if (max_v < freq) {
			max_v = freq;
		}
		total_v += freq;
	}

	width = length(sprintf("%s", 2 ** cnt)) + 1;
	if (width < min_width) {
		width = min_width
	}
	fmt_line = sprintf("%%%us .. %%-%us: %%-%us %%6s |%%-40s|\n", width, width, width);

	s = sprintf(">=(%s)", unit);
	e = sprintf("<(%s)", unit);
	printf(fmt_line, s, e, "count", "ratio", "distribution");

	fmt_line = sprintf("%%%uu .. %%-%uu: %%-%uu %%5.1f%% |%%-40s|\n", width, width, width);

	value = 1;
	from = 0;
	for (i = 0; i <= cnt; i++) {
		freq = hist[i];

		printf(fmt_line,
			   from, value, freq, freq * 100 / total_v, star(freq, max_v, 40));
		from = value;
		value *= 2;
	}

	fflush();
}

function show_rw_hist(rw, m, max_i, hist)
{
	cnt = max_i[rw][m];
	unit = m_unit[m];
	min_width = m_hist_w[m];

	show_hist(hist[rw][m], cnt, unit, min_width);
}

function show_zero_cnt(rw, m, raw)
{
	zero_cnt = 0;
	cnt = length(raw[rw][m]);
	for (i = 1; i <= cnt; i++) {
		v = raw[rw][m][i];
		if (v == 0) {
			zero_cnt++;
		}
	}

	printf("cnt %u, zero cnt %u\n", cnt, zero_cnt);
}

function show_dist(list, fmt, unit, divisor)
{
	cnt = asort(list);

	sum = 0;
	zero_cnt = 0;
	for (i = 1; i <= cnt; i++) {
		v = list[i];
		if (v == 0) {
			zero_cnt++;
		}
		sum += v;
	}

	/* mean, min, max */
	line_fmt = sprintf("cnt %%u sum %s%s mean %s%s min %s%s max %s%s\n",
					   fmt, unit, fmt, unit, fmt, unit, fmt, unit);

	printf(line_fmt, cnt, sum / divisor, sum / cnt / divisor,
		   list[1] / divisor, list[int(cnt)] / divisor);

	/* 50th, 75th, 99th */
	line_fmt = sprintf("50th %s%s 75th %s%s 99th %s%s\n",
			           fmt, unit, fmt, unit, fmt, unit);

	printf(line_fmt,
		   list[int((50 * cnt + 99) / 100)] / divisor,
		   list[int((75 * cnt + 99) / 100)] / divisor,
		   list[int((99 * cnt + 99) / 100)] / divisor);

	printf("zero cnt %u\n", zero_cnt);
}

function show_rw_dist(rw, m, raw)
{
	fmt = m_fmt[m];
	unit = m_unit[m];
	divisor = m_unit_divisor[m];

	show_dist(raw[rw][m], fmt, unit, divisor);
}

function update_qdepth_hist(cpu, depth)
{
	idx = 0;
	for (unit = 1; unit < depth; unit *= 2) {
		idx++;
	}

	cpu_qdepth_hist[cpu][idx]++;
	if (cpu_qdepth_max_i[cpu] < idx) {
		cpu_qdepth_max_i[cpu] = idx;
	}
}

function show_qdepth_dist(cpu)
{
	show_dist(cpu_qdepth_list[cpu], "%u", "", 1);
}

function show_qdepth_hist(cpu)
{
	show_hist(cpu_qdepth_hist[cpu], cpu_qdepth_max_i[cpu], "queue", 10);
}

function dump_qdepth_info()
{
	if (cpu_qdepth_cnt[-1] <= 0) {
		return;
	}

	printf("\nI/O Queue Depth:\n");
	for (cpu = -1; cpu <= max_cpu; cpu++) {
		cnt = cpu_qdepth_cnt[cpu];
		if (cnt <= 0) {
			continue;
		}

		if (cpu == -1) {
			cpu_str = "All CPUs";
		} else {
			cpu_str = sprintf("CPU %u", cpu);
		}

		print cpu_str
		if (0 < cpu_qdepth[cpu]) {
			printf("uncompleted IO count %u\n", cpu_qdepth[cpu]);
		}
		show_qdepth_dist(cpu);
		show_qdepth_hist(cpu);
		print "";
	}
}

function update_qdepth_raw(cpu, depth)
{
	cnt = cpu_qdepth_cnt[cpu];
	cpu_qdepth_list[cpu][cnt + 1] = depth;
	cpu_qdepth_cnt[cpu] = cnt + 1;
}

function _update_qdepth_dist(cpu)
{
	depth = cpu_qdepth[cpu];
	update_qdepth_hist(cpu, depth);
	update_qdepth_raw(cpu, depth);
}

function update_qdepth_dist(cpu)
{
	_update_qdepth_dist(-1);

	if (per_cpu_qdepth) {
		_update_qdepth_dist(cpu);
	}
}

function update_queue_depth(cpu, delta)
{
	/* -1 for all CPUs */
	cpu_qdepth[-1] += delta;

	if (per_cpu_qdepth) {
		cpu_qdepth[cpu] += delta;
	}
}

function dump_summary()
{
	printf("\nSummary:\n");

	if (1 < length(all_dev)) {
		printf("\nWarning: %u devices had been traced:", length(all_dev));
		for (dev in all_dev) {
			printf(" %s", dev);
		}
		printf("\n");
	}

	for (rw = READ; rw <= RDWR; rw++) {
		for (m in metrics) {
			cnt = raw_cnt[rw][m];

			if (cnt <= 0) {
				continue;
			}

			printf("%s %s\n", rw_str[rw], m);

			if (m != "seek") {
				show_rw_dist(rw, m, raw);
			} else {
				show_zero_cnt(rw, m, raw);
			}

			show_rw_hist(rw, m, max_i, hist);

			printf("\n");
		}
	}
}

function update_raw(rw, type, value)
{
	cnt = raw_cnt[rw][type];
	raw[rw][type][cnt + 1] = value;
	raw_cnt[rw][type]++;
}

function update_metrics(rw, type, from, value)
{
	idx = 0;
	for (unit = from; unit < value; unit *= 2) {
		idx++;
	}

	hist[rw][type][idx]++;
	if (max_i[rw][type] < idx) {
		max_i[rw][type] = idx;
	}

	update_raw(rw, type, value);
}

function update_r_or_w_metrics(rwbs, type, from, value)
{
	if (rwbs ~ "R") {
		rw = READ;
	} else {
		rw = WRITE;
	}

	update_metrics(rw, type, from, value);
}

function update_rw_metrics(type, from, value)
{
	update_metrics(RDWR, type, from, value);
}

function update_seek(pos, size)
{
	if (last_seek != "") {
		if (pos < last_seek) {
			delta = last_seek - pos;
		} else {
			delta = pos - last_seek;
		}

		update_rw_metrics("seek", 1024, delta * 512);
	}

	last_seek = pos + size;
}

function blk_rq_end()
{
	for (idx = NF; 1 <= idx; idx--) {
		if ("[" == substr($idx, 1, 1)) {
			break;
		}
	}

	return idx;
}

function blk_rq_parenthesis(start)
{
	for (idx = start; 1 <= idx; idx--) {
		if ("(" == substr($idx, 1, 1)) {
			break;
		}
	}

	return idx;
}

function get_cmd(who)
{
	# If no match found, "who" will be returned
	return gensub(issuer_ptn, "\\1", 1, who)
}

function get_pid(who)
{
	# If no match found, "who" will be returned
	return gensub(issuer_ptn, "\\2", 1, who)
}

function get_who(cpu_pos)
{
	who = $1;
	for (idx = 2; idx < cpu_pos; idx++) {
		who = who " " $idx
	}

	return who;
}

function parse_cpu(cpu_str)
{
	gsub("([[]0*|[]])", "", cpu_str);
	return strtonum(cpu_str);
}

function ignore_cur_line(reason)
{
	if (dbg) {
		printf("ignored: err %s, line %u \"%s\"\n", reason, NR, $0);
	}

	ignored_line++;
}

$1 == "#" \
{
	next;
}

{
	if (ftype == "blk") {
		cpu_pos = find_cpu_pos(3, NF);

		/* ...1  3650.802664:   8,48   A  WS 47748384 + 8 */
		if (NF - cpu_pos < 8) {
			ignore_cur_line("invalid NF")
			next;
		}

		cpu = parse_cpu($(cpu_pos));
		issuer = get_who(cpu_pos);
		pid = get_pid(issuer);
		cmd = get_cmd(issuer);
		dev = $(cpu_pos + 3);
		rwbs = $(cpu_pos + 5);

		time = $(cpu_pos + 2);
		sub(":", "", time);
		act = $(cpu_pos + 4);
		pos = $(cpu_pos + 6);
		size = $(cpu_pos + 8);
	} else if (ftype == "blkparse") {
		if (NF < 11 && $6 !~ "^[QIDC]$") {
			ignore_cur_line("invalid NF")
			next;
		}

		dev = $1;
		cpu = $2;
		time = $4;
		pid = $5;
		act = $6;
		rwbs = $7;
		pos = $8;
		size = $10;

		issuer = $11;
		for (idx = 12; idx <= NF; idx++) {
			issuer = issuer " " $idx;
		}
		cmd = issuer;
		issuer = sprintf("%s-%d", issuer, pid);
	} else {
		act = "";
		for (a in act2func_tbl) {
			func_magic = act2func_tbl[a];
			if ($0 ~ func_magic) {
				func_pos_ofs = func_pos_ofs_tbl[a];
				act = a;
				break;
			}
		}
		if (act == "") {
			next;
		}

		if (act != "Q") {
			minus = 4
		} else {
			minus = 6
		}
		end = blk_rq_end();
		if (end <= minus) {
			ignore_cur_line("invalid NF")
			next;
		}

		pos = $(end - 3);
		size = $(end - 1);

		if (pos == 0 && size == 0) {
			ignore_cur_line("nop bio")
			next;
		}

		if (act != "Q") {
			func_pos = blk_rq_parenthesis(end - 4) - 3 - func_pos_ofs;
		} else {
			func_pos = end - 6;
		}
		if (func_pos <= 3 || $func_pos != func_magic) {
			if (func_pos <= 3) {
				reason = "invalid NF"
			} else {
				reason = "no magic"
			}
			ignore_cur_line(reason)

			next;
		}

		dev = $(func_pos + 1);
		rwbs = $(func_pos + 2);
		time = $(func_pos - 1);
		sub(":", "", time);
		cpu_pos = func_pos - 3;
		cpu = parse_cpu($(cpu_pos));
		issuer = get_who(cpu_pos);
		pid = get_pid(issuer);
		cmd = get_cmd(issuer);
	}

	if (act != "C" && !match_filter(dev, issuer, rwbs)) {
		filtered_line++;
		next;
	}

	if (!(dev in all_dev)) {
		all_dev[dev] = 1;
	}

	if (act == start_act) {
		all_bio[dev, pos, size]["time"] = time;
		all_bio[dev, pos, size]["issuer"] = issuer;
		all_bio[dev, pos, size]["rwbs"] = rwbs;
		all_bio[dev, pos, size]["line"] = NR;
		all_bio[dev, pos, size]["cpu"] = cpu;
		all_bio[dev, pos, size]["pid"] = pid;
		all_bio[dev, pos, size]["cmd"] = cmd;
		if (!iodepth_when_c) {
			all_bio[dev, pos, size]["depth"] = cpu_qdepth[-1];
		}

		if (summary) {
			update_r_or_w_metrics(rwbs, "size", 1024, size * 512);
			if (0 < size) {
				update_seek(pos, size);
			}
		}

		if (dump_qdepth) {
			if (per_cpu_qdepth && max_cpu < cpu) {
				max_cpu = cpu;
			}
			update_queue_depth(cpu, 1);
			update_qdepth_dist(cpu);
		}
	} else if (act == "C") {
		if ((dev, pos, size) in all_bio && "time" in all_bio[dev, pos, size]) {
			start_time = all_bio[dev, pos, size]["time"]
			latency = (time - all_bio[dev, pos, size]["time"]) * 1e3;
			issuer = all_bio[dev, pos, size]["issuer"];
			pid = all_bio[dev, pos, size]["pid"];
			cmd = all_bio[dev, pos, size]["cmd"];
			rwbs = all_bio[dev, pos, size]["rwbs"];
			line = all_bio[dev, pos, size]["line"];
			start_cpu = all_bio[dev, pos, size]["cpu"];

			if (dump_qdepth) {
				update_queue_depth(start_cpu, -1);
			}

			if (outlier && lower <= latency) {
				if (!hdr_displayed) {
					printf("%-30s %-5s %-8s %11s %-4s %9s %6s:%-6s %5s(%s)\n",
						   "issuer", "rwbs", "dev",
						   "pos", "size", "latency", "start", "end", "depth",
						   iodepth_when_c ? "C" : start_act);
					hdr_displayed = 1;
				}

				if (!iodepth_when_c) {
					depth = all_bio[dev, pos, size]["depth"];
				} else {
					depth = cpu_qdepth[-1];
				}

				printf("%-30s %-5s %-8s %11u %-4u %6.1f ms %6u:%-6u %-8u\n",
					   issuer, rwbs, dev, pos, size, latency, line, NR,
					   depth);
			}

			if (summary) {
				update_r_or_w_metrics(rwbs, "latency", 1, latency);
			}

			if (bviewer_output) {
				printf(bviewer_fmt, start_time * 1e6, time * 1e6, rwbs,
					dev, pos, size, cpu, pid, cmd);
			}

			# delete all_bio[dev, pos, size]
			split("", all_bio[dev, pos, size]);
		} else {
			if (dbg) {
				printf("unmatched: line %u \"%s\"\n", NR, $0);
			}
			unmatched_line++;
		}
	}
}

END \
{
	if (error_summary && 0 < unmatched_line + ignored_line + filtered_line) {
		errors["unmatched_line"] = unmatched_line;
		errors["ignored_line"] = ignored_line;
		errors["filtered_line"] = filtered_line;

		printf("\nError:\n");
		for (e in errors) {
			cnt = errors[e];
			if (0 < cnt) {
				printf("%s: %u\n", e, cnt);
			}
		}
	}

	if (summary) {
		dump_summary();
	}

	if (dump_qdepth) {
		dump_qdepth_info();
	}
}
' $dst

exit 0

