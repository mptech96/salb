"""Observe host pressure; abort only our collector. Never kill a production PID."""
import pathlib, time
from native_isolation_checks import CheckBlocked

def host_pressure():
    fields=pathlib.Path('/proc/stat').read_text().splitlines()[0].split()[1:9]
    values=list(map(int,fields));idle=values[3]+values[4]
    mem=next(int(line.split()[1])*1024 for line in pathlib.Path('/proc/meminfo').read_text().splitlines() if line.startswith('MemAvailable:'))
    return {'total':sum(values),'idle':idle,'memory_available':mem}

def pressure_between(a,b):
    delta=b['total']-a['total']
    if delta<=0:raise CheckBlocked('HISTORY_CPU_SAMPLE_REQUIRED')
    return max(0.0,min(100.0,100.0*(1-(b['idle']-a['idle'])/delta)))

class SourcePressureGuard:
    def __init__(self,minimum_memory=1073741824,limit=85.0):self.last=None;self.high=0;self.samples=0;self.peak=0.0;self.minimum_memory=minimum_memory;self.limit=limit
    def observe(self,current):
        if current['memory_available']<self.minimum_memory:raise CheckBlocked('HISTORY_SOURCE_MEMORY_PRESSURE')
        if self.last is not None:
            cpu=pressure_between(self.last,current);self.peak=max(self.peak,cpu);self.samples+=1;self.high=self.high+1 if cpu>self.limit else 0
            if self.high>=5:raise CheckBlocked('HISTORY_SOURCE_CPU_PRESSURE')
        self.last=current
    def proof(self):return {'source_load_policy_observed':True,'cpu_samples':self.samples,'observed_peak_host_cpu_percent':round(self.peak,2),'cpu_limit_percent':self.limit,'sustained_limit_seconds':5,'minimum_available_memory_bytes':self.minimum_memory,'scope':'observed host headroom; no hard cap on production mysqld IO'}
