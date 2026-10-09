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
import { formatNumber, formatShortDate } from '@/lib/format';
import { useFuelColors } from '@/lib/fuel-colors';
import { useTranslation } from '@/lib/i18n';
import type { SalesChartData } from '@/types';

type Range = '7' | '30';

function formatChartDate(value: string): string {
    return formatShortDate(value);
}

export function SalesChart({ chart }: { chart: SalesChartData }) {
    const { t } = useTranslation();
    const fuelColors = useFuelColors();
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
                    <p className="text-muted-foreground text-sm">
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
                {/* Axes and numbers read left to right in every language (in RTL the y-axis labels
                    otherwise collide with the axis line). */}
                <div className="h-72 w-full" dir="ltr">
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
                            {chart.fuelTypes.map((fuelType) => (
                                <Line
                                    key={fuelType}
                                    type="monotone"
                                    dataKey={fuelType}
                                    name={fuelType}
                                    stroke={fuelColors.byName(fuelType).chart}
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
