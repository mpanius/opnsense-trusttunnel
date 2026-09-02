# Bandwidth Benchmark — v1.0.1 (исторический)

> Эти результаты относятся только к прежней сборке v1.0.1 на OPNsense
> 26.1.8_5. Они не подтверждают FreeBSD TUN в текущем client v1.1.5-rc.6.

Test environment: 2 isolated OPNsense 26.1.8_5 VMs
- Server: test VM, `trusttunnel_endpoint` :443
- Client: test VM, `trusttunnel_client` + `tun0`
- Both on `vmbr0` bridge (gigabit), no real WAN
- Server cert: self-signed `vpn.example.test`, user `alice`

Test date: 2026-05-23 08:25.

## Single-stream throughput

```
$ curl http://cachefly.cachefly.net/10mb.test  # route via tun0
HTTP 200 0.952247s 11011596 B/s size=10485760
  → 11.0 MB/s = 88 Mbit/s

$ curl http://cachefly.cachefly.net/100mb.test
HTTP 200 4.266007s 24579800 B/s size=104857600
  → 24.6 MB/s = 197 Mbit/s
```

Larger transfers achieve higher throughput because TCP slow-start has more
time to grow the congestion window before the connection completes.

## Parallel-stream aggregate

5× 10 MB downloads launched simultaneously:

| Stream | Speed |
|---|---|
| p1 | 2.45 MB/s |
| p2 | 4.28 MB/s |
| p3 | 5.24 MB/s |
| p4 | 2.26 MB/s |
| p5 | 2.16 MB/s |
| **Aggregate** | **15.63 MB/s = 125 Mbit/s** |

Wall time: 4 seconds for all 5. TCP fair-share across HTTP/2 multiplexed
streams under one TLS session.

## Sustained load (30s)

Continuous loop of `curl http://cachefly.cachefly.net/10mb.test` for 30s:

```
Requests: 43
Total bytes: 450,887,680  (~430 MB)
Avg speed: 15,029,589 B/s = 114 Mbit/s
```

Interface counters after the loop:

```
$ netstat -ibn | grep tun0
tun0  544174 Ipkts  646,985,706 Ibytes  544199 Opkts  28,303,784 Obytes
                                                 (0 Ierrs, 0 Idrop, 0 Coll)
```

## Stability

After all tests:

- `pgrep trusttunnel_client` → still running, same PID 63913
- TCP control session remained alive between the two test VMs
- No reconnects (single session ID throughout)
- 0 interface errors, 0 dropped packets
- `tun0` still UP,POINTOPOINT,RUNNING

## Asymmetry

Download-heavy traffic ratio:

| Direction | Bytes | Packets |
|---|---|---|
| In (from server) | 647 MB | 544,174 |
| Out (ACKs to server) | 28.3 MB | 544,199 |
| Ratio | **23:1** | 1:1 |

Each ACK ~52 bytes (TCP+IP header only), each data packet ~MTU-sized.
The 23:1 byte ratio confirms TCP ACK-only return traffic — exactly what
we'd expect for HTTP download workload.

## Reproducibility

```bash
# Add route for test target via tun0
route delete -host 205.234.175.175 2>/dev/null
route add -host 205.234.175.175 -interface tun0

# Run benchmark
for i in 1 2 3 4 5; do
    curl -sk --max-time 60 -o /tmp/p$i.bin \
        -w "p$i: %{speed_download} B/s\n" \
        http://cachefly.cachefly.net/10mb.test &
done
wait
```

## Verdict

For a v1 release with:
- HTTP/2 multiplexing over TLS 1.3 (TLS_CHACHA20_POLY1305_SHA256)
- FreeBSD-ported client through ~30 cumulative patches
- No kernel offloading, no DPDK, no AES-NI specific tuning

The historical build sustained 100+ Mbit/s with zero errors over 30 seconds.
Repeat the benchmark on the current release before making performance or
support claims.
