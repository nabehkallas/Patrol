import { useMemo, useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatNumber } from '@/lib/format';
import { useTranslation } from '@/lib/i18n';
import type { SalesChartData } from '@/types';

const COLORS = [
    '#2563eb',
    '#f97316',
    '#16a34a',
    '#dc2626',
    '#9333ea',
    '#0891b2',
];

type Range = '7' | '30';

function formatChartDate(value: string): string {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}

export function SalesChart({ chart }: { chart: SalesChartData }) {
    const { t } = useTranslation();
    const [range, setRange] = useState<Range>('7');

    const data = useMemo(() => {
        const days = range === '7' ? 7 : 30;

        return chart.data.slice(-days);
    }, [chart.data, range]);

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div>
                    <CardTitle>{t('dashboard.sales_chart_title')}</CardTitle>
                    <p className="text-sm text-muted-foreground">
                        {t('dashboard.sales_chart_description')}
                    </p>
                </div>
                <div className="flex gap-1">
                    <Button
                        type="button"
                        size="sm"
                        variant={range === '7' ? 'default' : 'outline'}
                        onClick={() => setRange('7')}
                    >
                        {t('dashboard.last_7_days')}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant={range === '30' ? 'default' : 'outline'}
                        onClick={() => setRange('30')}
                    >
                        {t('dashboard.last_30_days')}
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                <div className="h-72 w-full">
                    <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={data}>
                            <CartesianGrid
                                strokeDasharray="3 3"
                                className="stroke-muted"
                            />
                            <XAxis
                                dataKey="date"
                                tickFormatter={formatChartDate}
                                fontSize={12}
                                tickMargin={8}
                            />
                            <YAxis
                                fontSize={12}
                                width={40}
                                tickFormatter={(value: number) =>
                                    formatNumber(value)
                                }
                            />
                            <Tooltip
                                labelFormatter={(value) =>
                                    formatChartDate(String(value))
                                }
                                formatter={(value, name) => [
                                    formatNumber(Number(value)),
                                    name,
                                ]}
                            />
                            {chart.fuelTypes.map((fuelType, index) => (
                                <Line
                                    key={fuelType}
                                    type="monotone"
                                    dataKey={fuelType}
                                    name={fuelType}
                                    stroke={COLORS[index % COLORS.length]}
                                    strokeWidth={2}
                                    dot={false}
                                />
                            ))}
                        </LineChart>
                    </ResponsiveContainer>
                </div>
            </CardContent>
        </Card>
    );
}
