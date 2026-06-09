<script setup lang="ts">
import { ref, onMounted, computed, type Component } from "vue";
import { useResourcesAPI } from "@/composables/useResourcesAPI";
import type {
  UserResourcesResponse,
  ResourceLimits,
} from "@/composables/useResourcesAPI";
import {
  Loader2,
  AlertCircle,
  Activity,
  MemoryStick,
  Cpu,
  HardDrive,
  Server,
  Database,
  Archive,
  Network,
  Infinity as InfinityIcon,
} from "lucide-vue-next";

const { loading, error, getResources } = useResourcesAPI();
const resourcesData = ref<UserResourcesResponse | null>(null);

type ResourceKey = keyof ResourceLimits;

interface ResourceDef {
  key: ResourceKey;
  label: string;
  icon: Component;
  accent: string;
  format: (v: number) => string;
}

const formatBytes = (bytes: number): string => {
  if (bytes === 0) return "0 MB";
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} GB`;
  return `${Math.round(bytes)} MB`;
};

const formatPercentage = (value: number): string => `${value}%`;

const getUsagePercentage = (used: number, limit: number): number => {
  if (limit === 0) return 0;
  return Math.min(Math.round((used / limit) * 100), 100);
};

const getProgressTone = (
  percentage: number,
  overflow: boolean,
): "overflow" | "danger" | "warning" | "normal" | "success" => {
  if (overflow) return "overflow";
  if (percentage >= 90) return "danger";
  if (percentage >= 70) return "warning";
  if (percentage >= 50) return "normal";
  return "success";
};

const isOverflow = (used: number, limit: number): boolean => {
  if (limit === 0) return false;
  return used > limit;
};

const resourceDefs: ResourceDef[] = [
  {
    key: "memory_limit",
    label: "Memory",
    icon: MemoryStick,
    accent: "text-violet-400",
    format: formatBytes,
  },
  {
    key: "cpu_limit",
    label: "CPU",
    icon: Cpu,
    accent: "text-amber-400",
    format: formatPercentage,
  },
  {
    key: "disk_limit",
    label: "Storage",
    icon: HardDrive,
    accent: "text-sky-400",
    format: formatBytes,
  },
  {
    key: "server_limit",
    label: "Servers",
    icon: Server,
    accent: "text-emerald-400",
    format: (v) => v.toString(),
  },
  {
    key: "database_limit",
    label: "Databases",
    icon: Database,
    accent: "text-cyan-400",
    format: (v) => v.toString(),
  },
  {
    key: "backup_limit",
    label: "Backups",
    icon: Archive,
    accent: "text-orange-400",
    format: (v) => v.toString(),
  },
  {
    key: "allocation_limit",
    label: "Ports",
    icon: Network,
    accent: "text-indigo-400",
    format: (v) => v.toString(),
  },
];

const progressBarClass: Record<
  ReturnType<typeof getProgressTone>,
  string
> = {
  overflow: "bg-red-500",
  danger: "bg-red-500",
  warning: "bg-amber-500",
  normal: "bg-primary/70",
  success: "bg-emerald-500",
};

const badgeClass: Record<
  ReturnType<typeof getProgressTone>,
  string
> = {
  overflow: "text-red-400",
  danger: "text-red-400",
  warning: "text-amber-400",
  normal: "text-muted-foreground",
  success: "text-emerald-400",
};

const resourceItems = computed(() => {
  if (!resourcesData.value) return [];

  return resourceDefs.map((def) => {
    const used = resourcesData.value!.used[def.key] || 0;
    const limit = resourcesData.value!.limits[def.key] || 0;
    const maxLimit = resourcesData.value!.max_limits[def.key] || 0;
    const isUnlimited = limit === 0 || maxLimit === 0;
    const overflow = !isUnlimited && isOverflow(used, limit);
    const percentage = isUnlimited ? 0 : getUsagePercentage(used, limit);
    const tone = isUnlimited ? "success" : getProgressTone(percentage, overflow);

    return {
      ...def,
      used,
      limit,
      isUnlimited,
      overflow,
      percentage,
      tone,
      usedLabel: def.format(used),
      limitLabel: isUnlimited ? "∞" : def.format(limit),
    };
  });
});

const statusLabel = computed(() => {
  const items = resourceItems.value.filter((r) => !r.isUnlimited);
  const critical = items.filter((r) => r.overflow || r.percentage >= 90).length;
  const warning = items.filter(
    (r) => !r.overflow && r.percentage >= 70 && r.percentage < 90,
  ).length;

  if (critical > 0) return { text: `${critical} critical`, tone: "critical" as const };
  if (warning > 0) return { text: `${warning} warning`, tone: "warning" as const };
  return { text: "Healthy", tone: "healthy" as const };
});

const statusClass = {
  critical: "text-red-400 bg-red-500/10 border-red-500/25",
  warning: "text-amber-400 bg-amber-500/10 border-amber-500/25",
  healthy: "text-emerald-400 bg-emerald-500/10 border-emerald-500/25",
};

const loadResources = async () => {
  try {
    resourcesData.value = await getResources();
  } catch (err) {
    console.error("Failed to load resources:", err);
  }
};

onMounted(() => {
  loadResources();
});
</script>

<template>
  <div class="w-full rounded-xl border border-border/35 bg-transparent">
    <div
      class="flex items-center justify-between gap-3 border-b border-border/20 px-4 py-2.5"
    >
      <div class="flex min-w-0 items-center gap-2.5">
        <div
          class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 ring-1 ring-primary/15"
        >
          <Activity class="text-primary h-4 w-4" />
        </div>
        <div class="min-w-0">
          <h3
            class="text-foreground truncate text-sm font-semibold tracking-tight sm:text-base"
          >
            Resource Usage
          </h3>
          <p class="text-muted-foreground truncate text-[11px] sm:text-xs">
            Across all your servers
          </p>
        </div>
      </div>

      <span
        v-if="resourcesData"
        class="inline-flex shrink-0 items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-medium"
        :class="statusClass[statusLabel.tone]"
      >
        <AlertCircle
          v-if="statusLabel.tone === 'critical'"
          class="h-3 w-3"
        />
        {{ statusLabel.text }}
      </span>
    </div>

    <div class="p-3">
      <div
        v-if="loading && !resourcesData"
        class="flex items-center justify-center gap-2 py-8"
      >
        <Loader2 class="text-primary h-5 w-5 animate-spin" />
        <span class="text-muted-foreground text-xs">Loading resources…</span>
      </div>

      <div
        v-else-if="resourcesData"
        class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-2.5 lg:grid-cols-4"
      >
        <div
          v-for="item in resourceItems"
          :key="item.key"
          class="rounded-lg border border-border/30 bg-muted/5 px-2.5 py-2 transition-colors hover:border-border/50 hover:bg-muted/10 sm:px-3 sm:py-2.5"
          :class="{ 'border-red-500/35 bg-red-500/5': item.overflow }"
        >
          <div class="mb-1.5 flex items-center justify-between gap-1.5">
            <div class="flex min-w-0 items-center gap-1.5">
              <div
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted/40 ring-1 ring-border/30"
              >
                <component
                  :is="item.icon"
                  class="h-3.5 w-3.5"
                  :class="item.accent"
                />
              </div>
              <span
                class="text-muted-foreground truncate text-xs font-medium"
              >
                {{ item.label }}
              </span>
            </div>

            <span
              v-if="!item.isUnlimited"
              class="shrink-0 text-xs font-semibold tabular-nums"
              :class="badgeClass[item.tone]"
            >
              {{ item.percentage }}%
            </span>
            <InfinityIcon
              v-else
              class="h-3.5 w-3.5 shrink-0 text-emerald-400"
            />
          </div>

          <div class="mb-1.5 flex items-baseline gap-1 leading-none">
            <span
              class="text-foreground text-xs font-bold tabular-nums sm:text-sm"
              :class="{ 'text-red-400': item.overflow }"
            >
              {{ item.usedLabel }}
            </span>
            <span class="text-muted-foreground/70 text-xs">/</span>
            <span
              class="text-xs font-medium tabular-nums"
              :class="
                item.isUnlimited ? 'text-emerald-400' : 'text-muted-foreground'
              "
            >
              {{ item.limitLabel }}
            </span>
          </div>

          <div
            v-if="!item.isUnlimited"
            class="bg-muted/50 h-1.5 overflow-hidden rounded-full"
          >
            <div
              class="h-full rounded-full transition-all duration-300"
              :class="[
                progressBarClass[item.tone],
                item.overflow ? 'animate-pulse' : '',
              ]"
              :style="{ width: `${Math.min(item.percentage, 100)}%` }"
            />
          </div>
        </div>
      </div>

      <div
        v-else
        class="flex items-center justify-center gap-2 py-6 text-center"
      >
        <AlertCircle class="text-muted-foreground h-4 w-4 opacity-50" />
        <p class="text-muted-foreground text-xs">
          {{ error || "Failed to load resources" }}
        </p>
      </div>
    </div>
  </div>
</template>
